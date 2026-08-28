import { Link } from 'react-router-dom'

export default function NotFound() {
  return (
    <div className="screen empty-state">
      <p className="empty-title">صفحه یافت نشد</p>
      <Link to="/" className="btn btn-secondary">
        بازگشت به خانه
      </Link>
    </div>
  )
}
