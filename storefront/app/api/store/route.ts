import {canonicalStore} from '../../lib/canonical';
import {catalog,fail,integrationStatus,response,runtime,session} from '../../lib/server';
export const dynamic='force-dynamic';
export async function GET(request:Request){const canonical=canonicalStore(request);if(canonical)return canonical;try{const user=session(request),data=await catalog(!!user?.isAdmin);return response({...data,session:user,integration:integrationStatus(),settings:{storeName:'Chiqui',originPostcode:runtime().ZIPNOVA_ORIGIN_POSTCODE||''},isDemo:!integrationStatus().mercadopago});}catch(error){return fail(error);}}
