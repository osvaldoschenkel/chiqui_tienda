import {env} from 'cloudflare:workers';
import {redirect} from 'next/navigation';
import AdminPanel from '../components/AdminPanel';
import '../admin.css';

export default function AdminPage() {
  const url=(env as unknown as {LARAVEL_STORE_URL?:string}).LARAVEL_STORE_URL;
  if(url){const base=new URL(url);if(base.protocol!=='https:')throw new Error('El portal debe usar HTTPS');redirect(new URL('/',base.origin).toString());}
  return <AdminPanel />;
}
