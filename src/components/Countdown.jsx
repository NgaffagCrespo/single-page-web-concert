import { useEffect, useState } from 'react'
import { useI18n } from '../i18n.jsx'

const diff = (target) => {
  const ms = Math.max(0, new Date(target).getTime() - Date.now())
  return {
    days: Math.floor(ms / 86400000),
    hours: Math.floor((ms / 3600000) % 24),
    minutes: Math.floor((ms / 60000) % 60),
    seconds: Math.floor((ms / 1000) % 60),
  }
}

export default function Countdown({ target }) {
  const { t } = useI18n()
  const [left, setLeft] = useState(() => diff(target))
  useEffect(() => {
    const id = setInterval(() => setLeft(diff(target)), 1000)
    return () => clearInterval(id)
  }, [target])

  return (
    <div className="countdown" aria-label={t('countdown.label')}>
      {Object.entries(left).map(([label, value]) => (
        <div key={label} className="countdown__cell">
          <span className="countdown__value">{String(value).padStart(2, '0')}</span>
          <span className="countdown__label">{t('countdown.' + label)}</span>
        </div>
      ))}
    </div>
  )
}
