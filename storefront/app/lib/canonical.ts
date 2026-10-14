import {env} from 'cloudflare:workers';
export function canonicalStore(request:Request):Response|null {
 const configured=(env as unknown as {LARAVEL_STORE_URL?:string}).LARAVEL_STORE_URL;
 if(!configured)return null;
 const base=new URL(configured);if(base.protocol!=='https:')throw new Error('LARAVEL_STORE_URL must use HTTPS');
 const path=new URL(request.url).pathname;
 const destination=path.startsWith('/admin')?'/':path.startsWith('/cuenta')?'/mis-compras':'/tienda';
 return Response.redirect(new URL(destination,base.origin).toString(),303);
}
