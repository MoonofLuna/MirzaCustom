import type {
  Category,
  CustomPrice,
  Invoice,
  Panel,
  Product,
  PurchaseResult,
  ServiceDetail,
  TimeRange,
  TopupCreateResult,
  TopupMethod,
  UserInfo,
} from './types'

export class ApiError extends Error {
  status: number
  constructor(message: string, status: number) {
    super(message)
    this.status = status
  }
}

let authToken: string | null = null
let telegramUserId: number | null = null

export function setSession(token: string, userId: number) {
  authToken = token
  telegramUserId = userId
}

export function hasSession() {
  return authToken !== null && telegramUserId !== null
}

function apiBase() {
  const configured = window.__APP_CONFIG__?.apiUrl
  if (configured) return configured
  return (import.meta.env.VITE_API_URL as string | undefined) ?? 'http://localhost:8080/api'
}

export function verifyUrl() {
  return `${apiBase()}/verify.php`
}

interface RequestOptions {
  method?: 'GET' | 'POST'
  params?: Record<string, string | number | undefined>
  body?: Record<string, unknown>
}

async function request<T>(action: string, options: RequestOptions = {}): Promise<T> {
  if (!authToken || telegramUserId === null) {
    throw new ApiError('Session not ready', 0)
  }
  const method = options.method ?? 'GET'
  let url = `${apiBase()}/miniapp.php`
  let body: string | undefined

  if (method === 'GET') {
    const query = new URLSearchParams({ actions: action, user_id: String(telegramUserId) })
    for (const [key, value] of Object.entries(options.params ?? {})) {
      if (value !== undefined && value !== '') query.set(key, String(value))
    }
    url += `?${query.toString()}`
  } else {
    body = JSON.stringify({ actions: action, user_id: telegramUserId, ...(options.body ?? {}) })
  }

  const res = await fetch(url, {
    method,
    headers: {
      Authorization: `Bearer ${authToken}`,
      ...(method === 'POST' ? { 'Content-Type': 'application/json' } : {}),
    },
    body,
  })

  let json: { status?: boolean; msg?: string; obj?: T; success?: boolean; message?: string }
  try {
    json = await res.json()
  } catch {
    throw new ApiError('پاسخ نامعتبر از سرور', res.status)
  }

  const ok = json.status !== false && json.success !== false
  if (!res.ok || !ok) {
    throw new ApiError(json.msg ?? json.message ?? 'خطای نامشخص', res.status)
  }
  return (json.obj ?? (json as unknown)) as T
}

export const api = {
  countries: () => request<Panel[]>('countries'),
  categories: (countryId: string) => request<Category[]>('categories', { params: { country_id: countryId } }),
  timeRanges: (countryId: string) => request<TimeRange[]>('time_ranges', { params: { country_id: countryId } }),
  services: (countryId: string, categoryId?: string, timeRangeDay?: number) =>
    request<Product[]>('services', {
      params: { country_id: countryId, category_id: categoryId, time_range_day: timeRangeDay },
    }),
  customPrice: (countryId: string, trafficGb: number, timeDays: number) =>
    request<CustomPrice>('custom_price', {
      params: { country_id: countryId, traffic_gb: trafficGb, time_days: timeDays },
    }),
  purchase: (payload: {
    country_id: string
    service_id?: string
    custom_service?: { traffic_gb: number; time_days: number }
    custom_username?: string
    custom_note?: string
  }) => request<PurchaseResult>('purchase', { method: 'POST', body: payload }),
  invoices: (page: number, limit = 10, q?: string) =>
    request<Invoice[]>('invoices', { params: { page, limit, q } }),
  service: (username: string) => request<ServiceDetail>('service', { params: { username } }),
  userInfo: () => request<UserInfo>('user_info'),
  topupMethods: () => request<TopupMethod[]>('topup_methods'),
  createTopup: (amount: number, method: 'zarinpal' | 'zarinpey') =>
    request<TopupCreateResult>('topup_create', { method: 'POST', body: { amount, method } }),
}
