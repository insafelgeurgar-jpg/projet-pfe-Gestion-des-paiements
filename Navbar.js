import Link from 'next/link';
import { useState, useEffect } from 'react';
import { useRouter } from 'next/router';
import NotificationBell from './NotificationBell';

// Hardcoded demo accounts (used for quick login elsewhere)
const DEMO_USERS = [
  { id: 1, role: 'admin', name: 'Admin (System)' },
  { id: 2, role: 'client', name: 'Alice (Client)' },
  { id: 3, role: 'freelancer', name: 'Bob (Freelancer)' },
  { id: 4, role: 'accountant', name: 'Claire (Accountant)' },
];

const FINANCE_LINKS = [
  { href: '/', label: 'Dashboard' },
  { href: '/payments', label: 'Paiements' },
  { href: '/invoices', label: 'Factures' },
  { href: '/reports', label: 'Rapports' },
];

export default function Navbar() {
  const router = useRouter();
if (router.pathname === '/login') {
  return null;
}
  const [activeUser, setActiveUser] = useState(null);
  const [mobileOpen, setMobileOpen] = useState(false);

  // Load current user from localStorage on mount
  useEffect(() => {
    const stored = typeof window !== 'undefined' ? localStorage.getItem('mockUser') : null;
    if (stored) {
      setActiveUser(JSON.parse(stored));
    } else {
      // fallback to first demo user (admin) if not logged in – guard will redirect
      setActiveUser(DEMO_USERS[0]);
    }
  }, []);

  const handleLogout = () => {
    if (typeof window !== 'undefined') {
      localStorage.removeItem('mockUser');
    }
    router.replace('/login');
  };

  const isActive = (href) => router.pathname === href;

  return (
    <nav className="site-nav">
      <div className="container site-nav-inner">
        <div className="site-nav-left">
          <Link href="/" className="brand-logo">
            <svg viewBox="0 0 64 64" width="32" height="32" fill="var(--gold)" xmlns="http://www.w3.org/2000/svg">
              <circle cx="32" cy="32" r="30" stroke="var(--gold-light)" strokeWidth="2" fill="var(--bg-color)" />
              <text x="32" y="38" textAnchor="middle" fontSize="24" fontFamily="Inter, sans-serif" fill="var(--gold)">&#x1F4B0;</text>
            </svg>
            <span className="brand-text">Gestion de Paiement</span>
          </Link>

          <button
            type="button"
            className="nav-mobile-toggle"
            onClick={() => setMobileOpen(!mobileOpen)}
            aria-label="Menu"
          >
            ☰
          </button>

          <div className={`site-nav-links ${mobileOpen ? 'open' : ''}`}>
            {FINANCE_LINKS.map((link) => (
              <Link
                key={link.href}
                href={link.href}
                className={`nav-link ${isActive(link.href) ? 'nav-link-active' : ''}`}
                onClick={() => setMobileOpen(false)}
              >
                {link.label}
              </Link>
            ))}
            <Link href="/contests" className={`nav-link ${isActive('/contests') ? 'nav-link-active' : ''}`}>Concours</Link>
            {activeUser?.role === 'client' && (
              <Link href="/create" className="nav-link">Créer</Link>
            )}
            {activeUser?.id === 1 && (
              <Link href="/admin" className={`nav-link ${router.pathname.startsWith('/admin') ? 'nav-link-active' : ''}`}>Admin</Link>
            )}
          </div>
        </div>

        <div className="site-nav-right flex items-center">
          {activeUser && (
            <span className="nav-user-name hide-mobile" style={{ marginRight: '12px' }}>
              {activeUser.name}
            </span>
          )}
          <div className="flex-1"></div>
          <NotificationBell />
        </div>
      </div>
    </nav>
  );
}
