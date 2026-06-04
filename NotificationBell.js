import { useState, useEffect, useRef } from 'react';
import {
  fetchNotificationFeed,
  markNotificationRead,
  markAllNotificationsRead,
} from '../lib/financeApi';

export default function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState([]);
  const [count, setCount] = useState(0);
  const ref = useRef(null);

  const load = async () => {
    try {
      const data = await fetchNotificationFeed();
      setItems(data.items || []);
      setCount(data.unread_count ?? 0);
    } catch {
      /* ignore */
    }
  };

  useEffect(() => {
    load();
    const id = setInterval(load, 60000);
    return () => clearInterval(id);
  }, []);

  useEffect(() => {
    const onDoc = (e) => {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false);
    };
    document.addEventListener('click', onDoc);
    return () => document.removeEventListener('click', onDoc);
  }, []);

  const handleRead = async (n) => {
    if (!n.read) await markNotificationRead(n.id);
    load();
  };

  const handleReadAll = async () => {
    await markAllNotificationsRead();
    load();
  };

  return (
    <div className="notif-bell-wrap" ref={ref}>
      <button
        type="button"
        className="notif-bell-btn"
        onClick={() => setOpen(!open)}
        aria-label="Notifications"
      >
        🔔
        {count > 0 && <span className="notif-badge">{count > 9 ? '9+' : count}</span>}
      </button>
      {open && (
        <div className="notif-dropdown">
          <div style={{ padding: '12px 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '1px solid rgba(201,168,76,0.2)' }}>
            <strong>Notifications</strong>
            {count > 0 && (
              <button type="button" className="btn-secondary" style={{ padding: '4px 10px', fontSize: '0.75rem' }} onClick={handleReadAll}>
                Tout lire
              </button>
            )}
          </div>
          {items.length === 0 ? (
            <p className="text-muted" style={{ padding: '16px' }}>Aucune notification</p>
          ) : (
            items.map((n) => (
              <div
                key={n.id}
                className={`notif-item ${n.read ? '' : 'unread'}`}
                onClick={() => handleRead(n)}
                onKeyDown={(e) => e.key === 'Enter' && handleRead(n)}
                role="button"
                tabIndex={0}
              >
                <div className="notif-item-title">{n.title}</div>
                <div className="notif-item-msg">{n.message}</div>
                <div className="text-subtle" style={{ fontSize: '0.7rem', marginTop: '4px' }}>
                  {new Date(n.created_at).toLocaleString()}
                </div>
              </div>
            ))
          )}
        </div>
      )}
    </div>
  );
}
