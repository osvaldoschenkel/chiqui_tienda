<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class ArcaClient
{
    private function environment(): string
    {
        return IntegrationSettings::get('arca_environment', 'homologation');
    }

    private function client(string $wsdl): \SoapClient
    {
        return new \SoapClient($wsdl, ['soap_version' => SOAP_1_1, 'exceptions' => true, 'trace' => false, 'connection_timeout' => 15, 'cache_wsdl' => WSDL_CACHE_NONE, 'stream_context' => stream_context_create(['http' => ['timeout' => 25], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]])]);
    }

    public function auth(): array
    {
        $cert = IntegrationSettings::get('arca_certificate');
        $key = IntegrationSettings::get('arca_key');
        if (! is_file($cert ?? '') || ! is_file($key ?? '')) {
            throw ValidationException::withMessages(['arca' => 'Cargá certificado y clave privados.']);
        }
        $cacheKey = 'arca-ta-'.hash('sha256', $this->environment().IntegrationSettings::get('arca_cuit').hash_file('sha256', $cert));

        return Cache::lock($cacheKey.'-lock', 90)->block(10, function () use ($cacheKey, $cert, $key) {
            if ($auth = Cache::get($cacheKey)) {
                return $auth;
            }
            $directory = storage_path('app/private/arca-tmp');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $input = tempnam($directory, 'tra');
            $output = tempnam($directory, 'cms');
            chmod($input, 0600);
            chmod($output, 0600);
            try {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires'));
                $xml = '<?xml version="1.0" encoding="UTF-8"?><loginTicketRequest version="1.0"><header><uniqueId>'.time().'</uniqueId><generationTime>'.$now->modify('-10 minutes')->format(DATE_ATOM).'</generationTime><expirationTime>'.$now->modify('+10 minutes')->format(DATE_ATOM).'</expirationTime></header><service>wsfe</service></loginTicketRequest>';
                file_put_contents($input, $xml);
                $process = new Process(['openssl', 'cms', '-sign', '-in', $input, '-signer', $cert, '-inkey', $key, '-nodetach', '-binary', '-outform', 'DER', '-out', $output]);
                $process->setTimeout(15);
                $process->mustRun();
                $url = $this->environment() === 'production' ? 'https://wsaa.afip.gov.ar/ws/services/LoginCms?WSDL' : 'https://wsaahomo.afip.gov.ar/ws/services/LoginCms?WSDL';
                $ticket = $this->client($url)->loginCms(['in0' => base64_encode(file_get_contents($output))])->loginCmsReturn;
                $ta = simplexml_load_string($ticket, \SimpleXMLElement::class, LIBXML_NONET);
                if (! $ta || empty($ta->credentials->token) || empty($ta->credentials->sign)) {
                    throw new \RuntimeException('Ticket WSAA incompleto.');
                }
                $auth = ['Token' => (string) $ta->credentials->token, 'Sign' => (string) $ta->credentials->sign, 'Cuit' => (int) IntegrationSettings::get('arca_cuit')];
                $ttl = max(1, strtotime((string) $ta->header->expirationTime) - time() - 120);
                Cache::put($cacheKey, $auth, $ttl);

                return $auth;
            } finally {
                @unlink($input);
                @unlink($output);
            }
        });
    }

    public function call(string $method, array $args = []): array
    {
        $url = $this->environment() === 'production' ? 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL' : 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL';
        $response = $this->client($url)->__soapCall($method, [array_merge(['Auth' => $this->auth()], $args)]);

        return json_decode(json_encode($response->{$method.'Result'}, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public function last(int $pos, int $type): int
    {
        $r = $this->call('FECompUltimoAutorizado', ['PtoVta' => $pos, 'CbteTipo' => $type]);
        if (! empty($r['Errors'])) {
            throw new \RuntimeException('ARCA rechazó la consulta de numeración.');
        }

        return (int) $r['CbteNro'];
    }

    public function consult(int $pos, int $type, int $number): ?array
    {
        $r = $this->call('FECompConsultar', ['FeCompConsReq' => ['CbteTipo' => $type, 'CbteNro' => $number, 'PtoVta' => $pos]]);
        if (isset($r['ResultGet'])) {
            return $r['ResultGet'];
        }
        $errors = $r['Errors']['Err'] ?? [];
        if (isset($errors['Code'])) {
            $errors = [$errors];
        }if (count($errors) === 1 && (int) $errors[0]['Code'] === 602) {
            return null;
        }throw new \RuntimeException('No se pudo verificar el comprobante en ARCA. No se reemitió.');
    }

    public function authorize(array $request): array
    {
        return $this->call('FECAESolicitar', ['FeCAEReq' => $request]);
    }
}
