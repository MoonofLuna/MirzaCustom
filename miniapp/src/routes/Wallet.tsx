import { useEffect, useState } from 'react'
import { api, ApiError } from '../lib/api'
import { formatToman } from '../lib/format'
import { Button, Card, Chip, ErrorState, Input, LoadingScreen } from '../components/ui'
import { hapticResult, hapticTap, tg } from '../lib/telegram'
import { useSession } from '../state/SessionContext'
import type { TopupMethod } from '../lib/types'

export default function Wallet() {
  const { userInfo, refreshUserInfo } = useSession()
  const [methods, setMethods] = useState<TopupMethod[] | null>(null)
  const [selectedMethod, setSelectedMethod] = useState<TopupMethod | null>(null)
  const [amount, setAmount] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [pendingCheck, setPendingCheck] = useState(false)

  useEffect(() => {
    api
      .topupMethods()
      .then((list) => {
        setMethods(list)
        if (list.length > 0) setSelectedMethod(list[0])
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'خطا در دریافت روش‌های پرداخت'))
  }, [])

  useEffect(() => {
    if (!pendingCheck) return
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        refreshUserInfo()
        setPendingCheck(false)
      }
    }
    document.addEventListener('visibilitychange', onVisible)
    return () => document.removeEventListener('visibilitychange', onVisible)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pendingCheck])

  async function handleTopup() {
    if (!selectedMethod) return
    const value = parseInt(amount, 10)
    if (!value || value <= 0) {
      setError('مبلغ را به درستی وارد کنید')
      return
    }
    if (value < selectedMethod.min || (selectedMethod.max > 0 && value > selectedMethod.max)) {
      setError(`مبلغ باید بین ${formatToman(selectedMethod.min)} تا ${formatToman(selectedMethod.max)} باشد`)
      return
    }
    hapticTap()
    setBusy(true)
    setError(null)
    try {
      const result = await api.createTopup(value, selectedMethod.id)
      hapticResult(true)
      setPendingCheck(true)
      tg.openLink(result.pay_url)
    } catch (err) {
      hapticResult(false)
      setError(err instanceof ApiError ? err.message : 'خطا در ایجاد پرداخت')
    } finally {
      setBusy(false)
    }
  }

  if (error && methods === null) return <ErrorState message={error} />
  if (methods === null) return <LoadingScreen label="در حال بارگذاری کیف پول..." />

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>کیف پول</h1>
      </header>

      <Card className="wallet-balance-card">
        <span className="muted">موجودی فعلی</span>
        <span className="wallet-balance-value">{formatToman(userInfo?.balance ?? 0)}</span>
      </Card>

      {methods.length === 0 ? (
        <p className="muted">در حال حاضر درگاه پرداختی فعال نیست.</p>
      ) : (
        <Card className="topup-card">
          <p className="muted">افزایش موجودی</p>
          <Input
            type="number"
            inputMode="numeric"
            placeholder="مبلغ به تومان"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
          />
          <div className="chip-row">
            {methods.map((method) => (
              <Chip key={method.id} active={selectedMethod?.id === method.id} onClick={() => setSelectedMethod(method)}>
                {method.name}
              </Chip>
            ))}
          </div>
          {selectedMethod && (
            <p className="muted small">
              حداقل {formatToman(selectedMethod.min)} - حداکثر {formatToman(selectedMethod.max)}
            </p>
          )}
          {error && <p className="error-text">{error}</p>}
          <Button onClick={handleTopup} disabled={busy}>
            {busy ? 'در حال اتصال به درگاه...' : 'پرداخت و شارژ کیف پول'}
          </Button>
          {pendingCheck && <p className="muted small">پس از بازگشت از درگاه، موجودی به‌روزرسانی می‌شود.</p>}
        </Card>
      )}
    </div>
  )
}
