import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { formatGb } from '../lib/format'
import { Badge, Card, ErrorState, LoadingScreen } from '../components/ui'
import { CopyIcon } from '../components/icons'
import { useCopy } from '../hooks/useCopy'
import type { ServiceDetail as ServiceDetailType } from '../lib/types'

export default function ServiceDetail() {
  const { username } = useParams<{ username: string }>()
  const [service, setService] = useState<ServiceDetailType | null>(null)
  const [error, setError] = useState<string | null>(null)
  const { copy, copied } = useCopy()

  useEffect(() => {
    if (!username) return
    api
      .service(username)
      .then(setService)
      .catch((err) => setError(err instanceof ApiError ? err.message : 'خطا در دریافت اطلاعات سرویس'))
  }, [username])

  if (error) return <ErrorState message={error} />
  if (!service) return <LoadingScreen label="در حال دریافت اطلاعات سرویس..." />

  const usedPercent =
    service.total_traffic_gb > 0
      ? Math.min(100, Math.round((service.used_traffic_gb / service.total_traffic_gb) * 100))
      : 0

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>{service.product_name}</h1>
        <p className="muted">{service.username}</p>
      </header>

      <Card className="service-detail-card">
        <div className="traffic-bar">
          <div className="traffic-bar-fill" style={{ width: `${usedPercent}%` }} />
        </div>
        <div className="buy-row">
          <span className="muted">مصرف شده</span>
          <span>{formatGb(service.used_traffic_gb)}</span>
        </div>
        <div className="buy-row">
          <span className="muted">باقی‌مانده</span>
          <span>{formatGb(service.remaining_traffic_gb)}</span>
        </div>
        <div className="buy-row">
          <span className="muted">تاریخ انقضا</span>
          <span>{service.expiration_time}</span>
        </div>
        <div className="buy-row">
          <span className="muted">وضعیت اتصال</span>
          <Badge tone={service.status === 'active' ? 'success' : 'neutral'}>{service.online_at}</Badge>
        </div>
      </Card>

      {service.service_output.map((item, idx) => (
        <Card key={idx} className="config-card">
          <div className="config-card-header">
            <span className="muted">
              {item.type === 'link' ? 'لینک اشتراک' : item.type === 'password' ? 'رمز عبور' : 'کانفیگ'}
            </span>
            <button className="icon-btn" onClick={() => copy(item.value)} type="button">
              <CopyIcon />
            </button>
          </div>
          <p className="config-value">{item.value}</p>
        </Card>
      ))}
      {copied && <p className="toast">کپی شد</p>}
    </div>
  )
}
