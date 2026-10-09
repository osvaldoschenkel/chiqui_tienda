'use client';
import { FormEvent, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ArrowRight, ChevronDown, Headphones, LayoutDashboard, Menu, Search, ShoppingBag, UserRound } from 'lucide-react';
import '../storefront.css';

type Props = {search?:string;onSearch?:(value:string)=>void;cartCount?:number};
export default function StoreHeader({search,onSearch,cartCount=0}:Props) {
  const router=useRouter();
  const [localSearch,setLocalSearch]=useState('');
  const [menuOpen,setMenuOpen]=useState(false);
  function submit(event:FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if(onSearch) document.getElementById('productos')?.scrollIntoView({behavior:'smooth'});
    else router.push(`/?q=${encodeURIComponent(localSearch)}`);
  }
  return <header className="st-header">
    <div className="st-welcome"><div className="container st-welcome-inner"><span>Bienvenido a Chiqui · Tu próxima actualización empieza acá.</span><Link href="/cuenta">Seguí tus compras <ArrowRight size={12}/></Link></div></div>
    <div className="container st-main-header">
      <Link href="/" className="st-brand" aria-label="Chiqui, inicio"><span className="st-brand-mark">C<span>+</span></span><span>CHIQUI<small>COMPUTACIÓN & TECNOLOGÍA</small></span></Link>
      <form className="st-search" onSubmit={submit}><input aria-label="Buscar productos" placeholder="¿Qué estás buscando?" value={search??localSearch} onChange={event=>onSearch?onSearch(event.target.value):setLocalSearch(event.target.value)}/><button type="submit" aria-label="Buscar"><Search size={20}/></button></form>
      <Link href="/cuenta" className="st-header-help"><Headphones size={26}/><span>Estamos para ayudarte<strong>Mi cuenta y mis compras</strong></span></Link>
      <div className="st-header-actions"><Link href="/cuenta" aria-label="Mi cuenta"><UserRound size={24}/></Link><Link href="/#carrito" aria-label={`Carrito, ${cartCount} productos`} className="st-cart-link"><ShoppingBag size={25}/><span>{cartCount}</span></Link></div>
    </div>
    <nav className="st-nav" aria-label="Navegación principal"><div className="container st-nav-inner"><Link className="st-categories-link" href="/#categorias"><Menu size={20}/> Categorías <ChevronDown size={16}/></Link><button type="button" className="st-mobile-menu" aria-label="Abrir navegación" aria-expanded={menuOpen} onClick={()=>setMenuOpen(!menuOpen)}><Menu size={22}/></button><div className={`st-nav-links ${menuOpen?'is-open':''}`}><Link href="/#productos">Todos los productos</Link><Link href="/#destacados">Destacados</Link><Link href="/cuenta">Mis compras</Link><Link href="/admin" className="st-admin-link"><LayoutDashboard size={14}/> Administración</Link></div><span className="st-nav-caption">Tecnología para todos los días</span></div></nav>
  </header>;
}
