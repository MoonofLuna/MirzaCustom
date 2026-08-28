const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹']

export function toPersianDigits(value: string | number): string {
  return String(value).replace(/[0-9]/g, (d) => persianDigits[Number(d)])
}

export function formatToman(value: number | string): string {
  const num = Math.round(Number(value) || 0)
  return toPersianDigits(num.toLocaleString('en-US')) + ' تومان'
}

export function formatGb(value: number): string {
  if (!value || value === 0) return 'نامحدود'
  return toPersianDigits(Number(value).toLocaleString('en-US')) + ' گیگابایت'
}

export function formatDays(value: number): string {
  if (!value || value === 0) return 'نامحدود'
  return toPersianDigits(value) + ' روز'
}
