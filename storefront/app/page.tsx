import {env} from 'cloudflare:workers';
import {redirect} from 'next/navigation';
import Storefront from './components/Storefront';

export default function Home() {
  const url=(env as unknown as {LARAVEL_STORE_URL?:string}).LARAVEL_STORE_URL;
  if(url){const base=new URL(url);if(base.protocol!=='https:')throw new Error('El portal debe usar HTTPS');redirect(new URL('/tienda',base.origin).toString());}
  return <Storefront />;
}
