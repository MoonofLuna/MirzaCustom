import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { Card, EmptyState, ErrorState, LoadingScreen } from '../components/ui'
import { BagIcon, ChevronIcon } from '../components/icons'
import { hapticTap } from '../lib/telegram'
import type { Invoice } from '../lib/types'

const STATUS_LABELS: Record<string, string> = {
  active: 'فعال',
  end_of_time: 'پایان زمان',
  end_of_volume: 'پایان حجم',
  sendedwarn: 'هشدار پایان',
  send_on_hold: 'در انتظار',
}

export default function MyServices() {
  const navigate = useNavigate()
  const [invoices, setInvoices] = useState<Invoice[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .invoices(1, 10)
      .then(setInvoices)
      .catch((err) => setError(err instanceof ApiError ? err.message : 'خطا در دریافت سرویس‌ها'))
  }, [])

  if (error) return <ErrorState message={error} />
  if (!invoices) return <LoadingScreen label="در حال دریافت سرویس‌های شما..." />

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>سرویس‌های من</h1>
      </header>

      {invoices.length === 0 && (
        <EmptyState icon={<BagIcon size={32} />} title="هنوز سرویسی خریداری نکرده‌اید" />
      )}

      <section className="service-list">
        {invoices.map((invoice) => (
          <Card key={invoice.id_invoice} className="service-card">
            <button
              className="service-card-tap"
              onClick={() => {
                hapticTap()
                navigate(`/services/${encodeURIComponent(invoice.username)}`)
              }}
            >
              <div>
                <h3>{invoice.name_product}</h3>
                <p className="muted">{invoice.username}</p>
              </div>
              <div className="service-card-end">
                <span className={`badge badge-${invoice.status === 'active' ? 'success' : 'warning'}`}>
                  {STATUS_LABELS[invoice.status] ?? invoice.status}
                </span>
                <ChevronIcon className="chevron-back" />
              </div>
            </button>
          </Card>
        ))}
      </section>
    </div>
  )
}
