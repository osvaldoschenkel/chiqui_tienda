'use client';
import { useState } from 'react';
import { ImageIcon, Monitor, Play } from 'lucide-react';
import type { ProductMedia } from '../lib/domain';

export default function ProductGallery({media,name}:{media:ProductMedia[];name:string}) {
  const [selected,setSelected]=useState(0);
  const item=media[selected]??media[0];
  return <div className="st-gallery">
    <div className="st-gallery-stage">
      {item?.type==='video'?<video key={item.id} controls playsInline preload="metadata" aria-label={item.alt||`Video de ${name}`} src={item.url}/>:item?<img src={item.url} alt={item.alt||name} width={600} height={600}/>:<div className="st-product-placeholder"><Monitor size={96} strokeWidth={1}/><span>Imagen del producto</span></div>}
    </div>
    {media.length>1&&<div className="st-gallery-thumbs" aria-label="Galería del producto">{media.map((entry,index)=><button type="button" key={entry.id} onClick={()=>setSelected(index)} className={selected===index?'is-selected':''} aria-label={`${entry.type==='video'?'Ver video':'Ver imagen'} ${index+1}`} aria-pressed={selected===index}>{entry.type==='image'?<img src={entry.url} alt="" width={74} height={74}/>:<><Play size={22}/><small>Video</small></>}<span className="st-thumb-type">{entry.type==='video'?<Play size={10}/>:<ImageIcon size={10}/>}</span></button>)}</div>}
  </div>;
}
