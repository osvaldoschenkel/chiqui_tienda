export type Category = {id:string;name:string;parentId:string|null;slug:string};
export type ProductMedia = {id:string;url:string;type:'image'|'video';alt:string};
export type Product = {id:string;sku:string;name:string;brand:string;description:string;price:number;stock:number;categoryId:string;active:boolean;featured:boolean;weightGrams:number;heightCm:number;widthCm:number;lengthCm:number;media:ProductMedia[]};
export type Session = {id:string;name:string;email:string;isAdmin:boolean};
export type StoreData = {products:Product[];categories:Category[];session:Session|null;integration:{mercadopago:boolean;zipnova:boolean};settings:{storeName:string;originPostcode:string};isDemo:boolean};
export type CartLine = {productId:string;quantity:number};
export type Address = {name:string;email:string;phone:string;document:string;street:string;number:string;floor:string;city:string;state:string;postcode:string};
export type ShippingOption = {id:string;carrier:string;service:string;price:number;estimatedDelivery:string};
export type Order = {id:string;number:string;createdAt:string;status:string;paymentStatus:string;total:number;shippingCost:number;shippingMethod:string;trackingCode:string|null;trackingUrl:string|null;isDemo:boolean;items:{productId:string;name:string;quantity:number;unitPrice:number;image:string|null}[];address:Address;customerName:string;customerEmail:string;paymentUrl:string|null};
export function categoryPath(categories:Category[],id:string):string {const parts:string[]=[];const seen=new Set<string>();let current=categories.find(c=>c.id===id);while(current&&!seen.has(current.id)){seen.add(current.id);parts.unshift(current.name);current=categories.find(c=>c.id===current?.parentId);}return parts.join(' / ');}
export const ars = (value:number) => new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS',maximumFractionDigits:2,minimumFractionDigits:0}).format(value);
