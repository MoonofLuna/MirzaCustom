export interface AppConfig {
  basename: string
  prefix: string
  apiUrl: string
  assetPrefix: string
}

export interface TelegramThemeParams {
  bg_color?: string
  text_color?: string
  hint_color?: string
  link_color?: string
  button_color?: string
  button_text_color?: string
  secondary_bg_color?: string
  section_bg_color?: string
  destructive_text_color?: string
}

export interface TelegramUser {
  id: number
  first_name: string
  last_name?: string
  username?: string
  language_code?: string
}

export interface TelegramWebApp {
  initData: string
  initDataUnsafe: { user?: TelegramUser }
  themeParams: TelegramThemeParams
  colorScheme: 'light' | 'dark'
  ready: () => void
  expand: () => void
  close: () => void
  openLink: (url: string, options?: { try_instant_view?: boolean }) => void
  onEvent: (event: string, handler: () => void) => void
  offEvent: (event: string, handler: () => void) => void
  HapticFeedback: {
    impactOccurred: (style: 'light' | 'medium' | 'heavy' | 'rigid' | 'soft') => void
    notificationOccurred: (type: 'error' | 'success' | 'warning') => void
    selectionChanged: () => void
  }
  BackButton: {
    isVisible: boolean
    show: () => void
    hide: () => void
    onClick: (handler: () => void) => void
    offClick: (handler: () => void) => void
  }
  MainButton: {
    text: string
    isVisible: boolean
    isActive: boolean
    setText: (text: string) => void
    show: () => void
    hide: () => void
    enable: () => void
    disable: () => void
    showProgress: (leaveActive?: boolean) => void
    hideProgress: () => void
    onClick: (handler: () => void) => void
    offClick: (handler: () => void) => void
  }
}

declare global {
  interface Window {
    __APP_CONFIG__?: AppConfig
    Telegram?: { WebApp: TelegramWebApp }
  }
}

export interface Panel {
  id: string
  name: string
  is_custom: boolean
  is_username: boolean
  is_note: boolean
}

export interface Category {
  id: string
  name: string
}

export interface TimeRange {
  id: number
  name: string
  day: number
}

export interface Product {
  id: string
  name: string
  description: string
  price: number
  traffic_gb: number
  time_days: number
  category_id: string | number | null
  country_id: string
  time_range_id: number
}

export interface CustomPrice {
  price: number | false
  traffic_min: number
  traffic_max: number
  time_min: number
  time_max: number
}

export interface UserInfo {
  codeInvitation: string
  balance: number
  phone: string
  count_order: number
  count_payment: number
  group_type: string
  time_join: string
  affiliatescount: number
}

export interface Invoice {
  id_invoice: string
  id_user: string
  username: string
  Service_location: string
  time_sell: string
  name_product: string
  price_product: string
  Volume: string
  Service_time: string
  status: string
  note: string | null
}

export interface ServiceOutputItem {
  type: 'link' | 'config' | 'file' | 'password'
  value: string
  filename?: string
}

export interface ServiceDetail {
  status: string
  username: string
  product_name: string
  total_traffic_gb: number
  used_traffic_gb: number
  remaining_traffic_gb: number
  expiration_time: string
  last_subscription_update: string | null
  online_at: string
  service_output: ServiceOutputItem[]
}

export interface PurchaseResult {
  success: boolean
  message: string
  order_id: string
  service: { id: string; username: string; status: string; expire: number }
}

export interface TopupMethod {
  id: 'zarinpal' | 'zarinpey'
  name: string
  min: number
  max: number
}

export interface TopupCreateResult {
  order_id: string
  pay_url: string
}
