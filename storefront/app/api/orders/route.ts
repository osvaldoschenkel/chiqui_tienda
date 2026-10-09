import {findPayments} from '../../lib/integrations';
import {confirmPayment,expireReservations} from '../../lib/orders';
import {database,fail,integrationStatus,orderFromRow,requireUser,response,runtime} from '../../lib/server';
export const dynamic='force-dynamic';
export async function GET(request:Request){try{const user=requireUser(request);const db=database();const pending=await db.prepare("SELECT id FROM orders WHERE user_id=? AND is_demo=0 AND payment_status='Pendiente' ORDER BY created_at DESC LIMIT 5").bind(user.id).all<{id:string}>();if(integrationStatus().mercadopago)for(const o of pending.results){try{for(const p of await findPayments(runtime(),o.id))await confirmPayment(p);}catch{}}await expireReservations(user.id);const rows=await db.prepare('SELECT * FROM orders WHERE user_id=? ORDER BY created_at DESC LIMIT 200').bind(user.id).all();return response({orders:rows.results.map(orderFromRow)});}catch(error){return fail(error);}}
