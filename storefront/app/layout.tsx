import type { Metadata } from "next";
import "./theme.css";

export const metadata: Metadata = {
  title: "Chiqui · Tecnología a tu alcance",
  description: "Tienda de tecnología, panel de compras y administración de productos. Inspirada en Teracomp.",
  icons: {
    icon: "/favicon.svg",
    shortcut: "/favicon.svg",
  },
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="es-AR">
      <body className="antialiased">{children}</body>
    </html>
  );
}
