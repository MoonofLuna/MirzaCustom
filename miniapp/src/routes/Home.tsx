import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../lib/api'
import { formatDays, formatGb, formatToman } from '../lib/format'
import { Card, Chip, EmptyState, ErrorState, LoadingScreen } from '../components/ui'
import { BagIcon } from '../components/icons'
import type { Category, Panel, Product, TimeRange } from '../lib/types'
import { hapticTap } from '../lib/telegram'

export default function Home() {
  const navigate = useNavigate()
  const [panels, setPanels] = useState<Panel[] | null>(null)
  const [selectedPanel, setSelectedPanel] = useState<Panel | null>(null)
  const [categories, setCategories] = useState<Category[]>([])
  const [selectedCategory, setSelectedCategory] = useState<Category | null>(null)
  const [timeRanges, setTimeRanges] = useState<TimeRange[]>([])
  const [selectedTimeRange, setSelectedTimeRange] = useState<TimeRange | null>(null)
  const [products, setProducts] = useState<Product[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .countries()
      .then((list) => {
        setPanels(list)
        if (list.length > 0) setSelectedPanel(list[0])
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'خطا در دریافت لیست کشورها'))
  }, [])

  useEffect(() => {
    if (!selectedPanel) return
    setSelectedCategory(null)
    setSelectedTimeRange(null)
    api.categories(selectedPanel.id).then(setCategories).catch(() => setCategories([]))
    api.timeRanges(selectedPanel.id).then(setTimeRanges).catch(() => setTimeRanges([]))
  }, [selectedPanel])

  useEffect(() => {
    if (!selectedPanel) return
    setProducts(null)
    api
      .services(selectedPanel.id, selectedCategory?.id, selectedTimeRange?.day)
      .then(setProducts)
      .catch((err) => setError(err instanceof ApiError ? err.message : 'خطا در دریافت لیست سرویس‌ها'))
  }, [selectedPanel, selectedCategory, selectedTimeRange])

  if (error) return <ErrorState message={error} onRetry={() => window.location.reload()} />
  if (!panels) return <LoadingScreen label="در حال دریافت لیست سرورها..." />

  return (
    <div className="screen">
      <header className="screen-header">
        <h1>خرید سرویس</h1>
        <p className="muted">سرور، دسته‌بندی و مدت زمان مورد نظرتان را انتخاب کنید</p>
      </header>

      {panels.length > 1 && (
        <section className="chip-row">
          {panels.map((panel) => (
            <Chip key={panel.id} active={selectedPanel?.id === panel.id} onClick={() => setSelectedPanel(panel)}>
              {panel.name}
            </Chip>
          ))}
        </section>
      )}

      {categories.length > 0 && (
        <section className="chip-row">
          <Chip active={!selectedCategory} onClick={() => setSelectedCategory(null)}>
            همه
          </Chip>
          {categories.map((cat) => (
            <Chip key={cat.id} active={selectedCategory?.id === cat.id} onClick={() => setSelectedCategory(cat)}>
              {cat.name}
            </Chip>
          ))}
        </section>
      )}

      {timeRanges.length > 0 && (
        <section className="chip-row">
          <Chip active={!selectedTimeRange} onClick={() => setSelectedTimeRange(null)}>
            همه مدت‌ها
          </Chip>
          {timeRanges.map((range) => (
            <Chip
              key={`${range.id}-${range.day}`}
              active={selectedTimeRange?.day === range.day}
              onClick={() => setSelectedTimeRange(range)}
            >
              {range.name}
            </Chip>
          ))}
        </section>
      )}

      <section className="product-list">
        {products === null && <LoadingScreen label="در حال دریافت پلن‌ها..." />}
        {products?.length === 0 && (
          <EmptyState icon={<BagIcon size={32} />} title="پلنی یافت نشد" hint="فیلتر دیگری را امتحان کنید" />
        )}
        {products?.map((product) => (
          <Card
            key={product.id}
            className="product-card"
          >
            <button
              className="product-card-tap"
              onClick={() => {
                hapticTap()
                navigate('/buy', { state: { product } })
              }}
            >
              <div className="product-card-main">
                <h3>{product.name}</h3>
                <p className="muted">
                  {formatGb(product.traffic_gb)} · {formatDays(product.time_days)}
                </p>
              </div>
              <div className="product-card-price">{formatToman(product.price)}</div>
            </button>
          </Card>
        ))}
      </section>
    </div>
  )
}
