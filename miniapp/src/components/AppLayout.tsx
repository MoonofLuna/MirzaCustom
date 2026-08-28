import { useEffect } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { tg } from '../lib/telegram'
import { BagIcon, HomeIcon, UserIcon, WalletIcon } from './icons'

const TAB_ROUTES = ['/', '/services', '/wallet', '/account']

export function AppLayout() {
  const location = useLocation()
  const navigate = useNavigate()
  const isTabRoute = TAB_ROUTES.includes(location.pathname)

  useEffect(() => {
    if (isTabRoute) {
      tg.BackButton.hide()
      return
    }
    tg.BackButton.show()
    const handler = () => navigate(-1)
    tg.BackButton.onClick(handler)
    return () => tg.BackButton.offClick(handler)
  }, [location.pathname, isTabRoute, navigate])

  return (
    <div className="app-shell">
      <main className="app-content">
        <Outlet />
      </main>
      <nav className="bottom-nav">
        <NavLink to="/" end className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
          <HomeIcon />
          <span>خانه</span>
        </NavLink>
        <NavLink to="/services" className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
          <BagIcon />
          <span>سرویس‌ها</span>
        </NavLink>
        <NavLink to="/wallet" className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
          <WalletIcon />
          <span>کیف پول</span>
        </NavLink>
        <NavLink to="/account" className={({ isActive }) => `nav-item ${isActive ? 'nav-active' : ''}`}>
          <UserIcon />
          <span>حساب</span>
        </NavLink>
      </nav>
    </div>
  )
}
