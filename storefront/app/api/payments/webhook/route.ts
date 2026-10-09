import {fetchPayment,validateMPWebhook} from '../../../lib/integrations';
import {confirmPayment} from '../../../lib/orders';
import {fail,response,runtime} from '../../../lib/server';
export const dynamic='force-dynamic';
export async function POST(request:Request){try{const e=runtime();if(!e.MERCADOPAGO_WEBHOOK_SECRET||!e.MERCADOPAGO_ACCESS_TOKEN)return response({error:'Mercado Pago todavía no está configurado.'},503);const id=await validateMPWebhook(request,e.MERCADOPAGO_WEBHOOK_SECRET);if(!id)return response({error:'Firma inválida.'},401);await confirmPayment(await fetchPayment(e,id));return response({received:true});}catch(error){return fail(error);}}
