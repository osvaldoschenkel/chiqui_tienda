import type {Category,Product} from './domain';
export const seedCategories:Category[]=[
{id:'cables',name:'Cables y adaptadores',slug:'cables-y-adaptadores',parentId:null},
{id:'video',name:'Video y pantallas',slug:'video-y-pantallas',parentId:'cables'},
{id:'hdmi',name:'HDMI',slug:'hdmi',parentId:'video'},
{id:'red',name:'Redes',slug:'redes',parentId:'cables'},
{id:'fuentes',name:'Fuentes',slug:'fuentes',parentId:null},
{id:'notebook',name:'Cargadores de notebook',slug:'cargadores-de-notebook',parentId:'fuentes'},
{id:'componentes',name:'Hardware',slug:'hardware',parentId:null},
{id:'almacenamiento',name:'Almacenamiento',slug:'almacenamiento',parentId:'componentes'},
{id:'ssd',name:'SSD',slug:'ssd',parentId:'almacenamiento'},
{id:'mantenimiento',name:'Mantenimiento',slug:'mantenimiento',parentId:'componentes'},
{id:'perifericos',name:'Periféricos',slug:'perifericos',parentId:null},
{id:'audio',name:'Audio',slug:'audio',parentId:'perifericos'},
{id:'impresoras',name:'Impresoras e insumos',slug:'impresoras-e-insumos',parentId:null},
{id:'oficina',name:'Oficina',slug:'oficina',parentId:null}];
const product=(id:string,name:string,brand:string,price:number,stock:number,categoryId:string,image:string,weightGrams:number,heightCm:number,widthCm:number,lengthCm:number,description:string):Product=>({id,sku:id.toUpperCase(),name,brand,price,stock,categoryId,active:true,featured:true,weightGrams,heightCm,widthCm,lengthCm,description,media:[{id:'seed-'+id,url:'/products/'+image+'.svg',type:'image',alt:name}]});
export const seedProducts:Product[]=[
product('tera-001','Fuente para notebook 65W','Universal',38000,18,'notebook','charger',480,6,14,18,'Cargador universal de 65W para notebook. Consultá compatibilidad de voltaje y conector antes de comprar. Producto y precio de ejemplo.'),
product('tera-002','Cable de red RJ45 · 5 metros','IntCo',4500,45,'red','cable',210,5,18,18,'Cable de red Cat5E armado, de 5 metros. Para conectar tu PC, router o consola. Producto y precio de ejemplo.'),
product('tera-003','Adaptador DisplayPort a VGA','IntCo',12000,20,'video','adapter',100,4,8,12,'Adaptador de video para conectar equipos DisplayPort a una pantalla VGA. Verificá compatibilidad y resolución. Producto y precio de ejemplo.'),
product('tera-004','Cable HDMI 2.0 · 3 metros','IntCo',9000,32,'hdmi','cable',230,5,18,18,'Conexión HDMI para monitor, TV o consola. Longitud: 3 metros. Producto y precio de ejemplo.'),
product('tera-005','Pasta térmica Arctic MX-4 · 4 g','Arctic',18000,16,'mantenimiento','thermal',70,3,8,16,'Pasta térmica para mantenimiento de procesadores. Presentación de 4 gramos. Producto y precio de ejemplo.'),
product('tera-006','SSD SATA · 480 GB','Kingston',64900,12,'ssd','ssd',180,4,12,15,'Almacenamiento sólido SATA de 480 GB para renovar tu equipo. Producto, marca y precio de ejemplo.'),
product('tera-007','Auriculares inalámbricos','Genius',54900,24,'audio','headphones',520,12,20,23,'Auriculares con conexión Bluetooth, micrófono y almohadillas cómodas. Producto, marca y precio de ejemplo.'),
product('tera-008','Mouse inalámbrico','Logitech',24900,26,'perifericos','mouse',160,6,10,15,'Mouse inalámbrico compacto para tu trabajo de todos los días. Producto, marca y precio de ejemplo.')];
