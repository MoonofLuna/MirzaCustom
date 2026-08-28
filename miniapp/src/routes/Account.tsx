import { Card, LoadingScreen } from '../components/ui'
import { CopyIcon } from '../components/icons'
import { formatToman } from '../lib/format'
import { useCopy } from '../hooks/useCopy'
import { useSession } from '../state/SessionContext'
import { tg } from '../lib/telegram'

export default function Account() {
  const { userInfo } = useSession()
  const { copy, copied } = useCopy()

  if (!userInfo) return <LoadingScreen label="در حال بارگذاری حساب کاربری..." />

  const telegramUser = tg.initDataUnsafe.user

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>حساب کاربری</h1>
        {telegramUser && <p className="muted">@{telegramUser.username ?? telegramUser.first_name}</p>}
      </header>

      <Card className="account-card">
        <div className="buy-row">
          <span className="muted">موجودی کیف پول</span>
          <span>{formatToman(userInfo.balance)}</span>
        </div>
        <div className="buy-row">
          <span className="muted">شماره تلفن</span>
          <span>{userInfo.phone}</span>
        </div>
        <div className="buy-row">
          <span className="muted">نوع کاربری</span>
          <span>{userInfo.group_type}</span>
        </div>
        <div className="buy-row">
          <span className="muted">تاریخ عضویت</span>
          <span>{userInfo.time_join}</span>
        </div>
        <div className="buy-row">
          <span className="muted">تعداد خرید</span>
          <span>{userInfo.count_order}</span>
        </div>
      </Card>

      <Card className="account-card">
        <div className="config-card-header">
          <span className="muted">کد دعوت شما</span>
          <button className="icon-btn" onClick={() => copy(userInfo.codeInvitation)} type="button">
            <CopyIcon />
          </button>
        </div>
        <p className="config-value">{userInfo.codeInvitation}</p>
        <p className="muted small">تعداد زیرمجموعه‌ها: {userInfo.affiliatescount}</p>
      </Card>
      {copied && <p className="toast">کپی شد</p>}
    </div>
  )
}
