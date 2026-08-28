import type { TelegramWebApp } from './types'

function createDevStub(): TelegramWebApp {
  const noop = () => {}
  const fakeUser = { id: 123456789, first_name: 'Test', username: 'testuser', language_code: 'fa' }
  return {
    initData:
      'user=%7B%22id%22%3A123456789%2C%22first_name%22%3A%22Test%22%2C%22username%22%3A%22testuser%22%7D&auth_date=1700000000&hash=devstub',
    initDataUnsafe: { user: fakeUser },
    themeParams: {
      bg_color: '#0f1115',
      text_color: '#f3f4f6',
      hint_color: '#8b93a1',
      link_color: '#4da3ff',
      button_color: '#2f7bf6',
      button_text_color: '#ffffff',
      secondary_bg_color: '#171a21',
      section_bg_color: '#171a21',
      destructive_text_color: '#ff5c5c',
    },
    colorScheme: 'dark',
    ready: noop,
    expand: noop,
    close: noop,
    openLink: (url) => window.open(url, '_blank'),
    onEvent: noop,
    offEvent: noop,
    HapticFeedback: { impactOccurred: noop, notificationOccurred: noop, selectionChanged: noop },
    BackButton: { isVisible: false, show: noop, hide: noop, onClick: noop, offClick: noop },
    MainButton: {
      text: '',
      isVisible: false,
      isActive: true,
      setText: noop,
      show: noop,
      hide: noop,
      enable: noop,
      disable: noop,
      showProgress: noop,
      hideProgress: noop,
      onClick: noop,
      offClick: noop,
    },
  }
}

export const tg: TelegramWebApp =
  typeof window !== 'undefined' && window.Telegram?.WebApp ? window.Telegram.WebApp : createDevStub()

export function initTelegram() {
  tg.ready()
  tg.expand()
  applyTheme()
  tg.onEvent('themeChanged', applyTheme)
}

function applyTheme() {
  const root = document.documentElement
  const theme = tg.themeParams
  root.style.setProperty('--tg-bg', theme.bg_color ?? '#0f1115')
  root.style.setProperty('--tg-text', theme.text_color ?? '#f3f4f6')
  root.style.setProperty('--tg-hint', theme.hint_color ?? '#8b93a1')
  root.style.setProperty('--tg-link', theme.link_color ?? '#4da3ff')
  root.style.setProperty('--tg-button', theme.button_color ?? '#2f7bf6')
  root.style.setProperty('--tg-button-text', theme.button_text_color ?? '#ffffff')
  root.style.setProperty('--tg-secondary-bg', theme.secondary_bg_color ?? '#171a21')
  root.style.setProperty('--tg-section-bg', theme.section_bg_color ?? '#171a21')
  root.dataset.colorScheme = tg.colorScheme
}

export function hapticTap() {
  tg.HapticFeedback.impactOccurred('light')
}

export function hapticResult(ok: boolean) {
  tg.HapticFeedback.notificationOccurred(ok ? 'success' : 'error')
}
