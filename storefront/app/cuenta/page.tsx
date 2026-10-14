import {env} from 'cloudflare:workers';
import {redirect} from 'next/navigation';
import CustomerPanel from '../components/CustomerPanel';
import '../customer.css';

export default function AccountPage() {
  const url=(env as unknown as {LARAVEL_STORE_URL?:string}).LARAVEL_STORE_URL;
  if(url){const base=new URL(url);if(base.protocol!=='https:')throw new Error('El portal debe usar HTTPS');redirect(new URL('/mis-compras',base.origin).toString());}
  return <CustomerPanel />;
}
