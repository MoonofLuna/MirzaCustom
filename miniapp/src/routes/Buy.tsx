import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { formatDays, formatGb, formatToman } from '../lib/format'
import { Card, ErrorState } from '../components/ui'
import { hapticResult, tg } from '../lib/telegram'
import { useSession } from '../state/SessionContext'
import type { Product } from '../lib/types'

export default function Buy() {
  const location = useLocation()
  const navigate = useNavigate()
  const { userInfo, refreshUserInfo } = useSession()
  const product = (location.state as { product?: Product } | null)?.product

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!product) return

    tg.MainButton.setText(`پرداخت ${formatToman(product.price)}`)
    tg.MainButton.show()

    const onClick = async () => {
      if (busy) return
      setBusy(true)
      setError(null)
      tg.MainButton.showProgress()
      try {
        const result = await api.purchase({ country_id: product.country_id, service_id: product.id })
        hapticResult(true)
        await refreshUserInfo()
        navigate(`/services/${encodeURIComponent(result.service.username)}`, { replace: true })
      } catch (err) {
        hapticResult(false)
        setError(err instanceof ApiError ? err.message : 'خرید ناموفق بود')
      } finally {
        tg.MainButton.hideProgress()
        setBusy(false)
      }
    }

    tg.MainButton.onClick(onClick)
    return () => {
      tg.MainButton.offClick(onClick)
      tg.MainButton.hide()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [product, busy])

  if (!product) {
    return <ErrorState message="اطلاعات محصول یافت نشد" onRetry={() => navigate('/', { replace: true })} />
  }

  const balance = userInfo?.balance ?? 0
  const insufficientBalance = balance < product.price

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>{product.name}</h1>
      </header>

      <Card className="buy-summary">
        <div className="buy-row">
          <span className="muted">حجم</span>
          <span>{formatGb(product.traffic_gb)}</span>
        </div>
        <div className="buy-row">
          <span className="muted">مدت زمان</span>
          <span>{formatDays(product.time_days)}</span>
        </div>
        {product.description && (
          <div className="buy-row">
            <span className="muted">توضیحات</span>
            <span>{product.description}</span>
          </div>
        )}
        <div className="buy-row buy-row-total">
          <span>مبلغ قابل پرداخت</span>
          <span>{formatToman(product.price)}</span>
        </div>
      </Card>

      <Card className="buy-balance">
        <span className="muted">موجودی کیف پول شما</span>
        <span>{formatToman(balance)}</span>
      </Card>

      {insufficientBalance && (
        <p className="warning-text">موجودی کیف پول شما کافی نیست. ابتدا کیف پول خود را شارژ کنید.</p>
      )}
      {error && <p className="error-text">{error}</p>}
    </div>
  )
}
