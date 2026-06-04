import { useState, useEffect } from 'react';
import Layout from '../../components/Layout';
import { apiFetch, getMockUser } from '../../lib/api';

export default function AdminPayouts() {
  const [payouts, setPayouts] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const mockUser = typeof window !== 'undefined' ? getMockUser() : { id: 1 };

  useEffect(() => {
    if (mockUser.id !== 1) {
      setError('Admin only (user id 1).');
      setLoading(false);
      return;
    }
    loadPayouts();
  }, []);

  const loadPayouts = async () => {
    try {
      const data = await apiFetch('/api/admin/payouts');
      setPayouts(data);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const confirmManual = async (payoutId) => {
    const ref = prompt('Enter offline transaction reference:');
    if (!ref) return;
    try {
      await apiFetch(`/api/admin/payout/${payoutId}/confirm`, {
        method: 'POST',
        body: JSON.stringify({ transaction_reference: ref }),
      });
      loadPayouts();
    } catch (err) {
      setError(err.message);
    }
  };

  if (loading) return <Layout><p>Loading...</p></Layout>;

  return (
    <Layout>
      <h1 style={{ fontSize: '2rem', fontWeight: 800, marginBottom: '24px' }}>Admin payout dashboard</h1>
      {error && <p className="alert-error">{error}</p>}
      <div className="glass-panel">
        {payouts.length === 0 ? (
          <p className="text-muted">No payouts yet.</p>
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '0.9rem' }}>
            <thead>
              <tr className="text-muted" style={{ textAlign: 'left' }}>
                <th style={{ padding: '8px' }}>Contest</th>
                <th>Freelancer</th>
                <th>Method</th>
                <th>Net</th>
                <th>Status</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {payouts.map((p) => (
                <tr key={p.id} style={{ borderTop: '1px solid var(--border-color)' }}>
                  <td style={{ padding: '8px' }}>{p.contest_title}</td>
                  <td>{p.freelancer_name}</td>
                  <td>{p.method}</td>
                  <td>${(p.net_amount / 100).toFixed(2)}</td>
                  <td>{p.status}</td>
                  <td>
                    {p.status === 'processing' &&
                      ['cashplus', 'wafacash', 'bank_transfer'].includes(p.method) && (
                        <button type="button" className="btn-secondary" onClick={() => confirmManual(p.id)}>
                          Confirm paid
                        </button>
                      )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </Layout>
  );
}
