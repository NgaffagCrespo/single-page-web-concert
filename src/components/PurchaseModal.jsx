import { useEffect, useRef, useState } from 'react'
import { formatFCFA, MAX_TICKETS_PER_ORDER } from '../config/tickets'
import { useI18n } from '../i18n.jsx'

const RESERVE_URL = import.meta.env.VITE_RESERVE_URL || '/api/reserve.php'
const REDIRECT_SECONDS = 10

// Réservation : le serveur enregistre les billets (statut RESERVED) et envoie un
// email de confirmation. Le client voit le message pendant 10 secondes, puis il est
// redirigé vers la page de paiement Maviance dans le même onglet.
export default function PurchaseModal({ tier, remaining, onClose }) {
  const { t, lang } = useI18n()
  const [form, setForm] = useState({ name: '', phone: '', email: '', quantity: 1 })
  const [error, setError] = useState('')
  const [sending, setSending] = useState(false)
  const [reservation, setReservation] = useState(null)
  const [seconds, setSeconds] = useState(REDIRECT_SECONDS)
  const timer = useRef(null)

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  // Compte à rebours puis redirection vers Maviance (même onglet)
  useEffect(() => {
    if (!reservation) return undefined
    timer.current = setInterval(() => {
      setSeconds((s) => {
        if (s <= 1) {
          clearInterval(timer.current)
          window.location.href = tier.paymentLink
          return 0
        }
        return s - 1
      })
    }, 1000)
    return () => clearInterval(timer.current)
  }, [reservation, tier.paymentLink])

  const update = (field) => (e) => setForm({ ...form, [field]: e.target.value })
  // Quantité maximale : 10 par commande, et jamais plus que les places restantes
  const maxQty = Math.max(1, Math.min(MAX_TICKETS_PER_ORDER, remaining ?? MAX_TICKETS_PER_ORDER))
  const setQty = (q) => setForm({ ...form, quantity: Math.min(maxQty, Math.max(1, q)) })

  const submit = async (e) => {
    e.preventDefault()
    setError('')
    if (form.name.trim().length < 3) return setError(t('error.name'))
    const phone = form.phone.trim()
    if (!phone) return setError(t('error.phoneMissing'))
    // 9 chiffres minimum (ex : 690 00 00 00 ou +237 690 00 00 00), même règle que le serveur
    if (!/^\+?[0-9][0-9 .-]{8,18}$/.test(phone) || phone.replace(/\D/g, '').length < 9) {
      return setError(t('error.phone'))
    }
    if (!tier.paymentLink) {
      return setError(t('error.noLink'))
    }

    setSending(true)
    try {
      const res = await fetch(RESERVE_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          tierId: tier.id,
          quantity: form.quantity,
          name: form.name.trim(),
          phone,
          email: form.email.trim(),
          lang, // langue des éventuels messages d'erreur du serveur
        }),
      })
      const data = await res.json().catch(() => ({}))
      if (!res.ok || !data.reference) throw new Error(data.error || t('error.generic'))
      setReservation({ ...data, quantity: form.quantity, email: form.email.trim(), name: form.name.trim(), phone })
    } catch (err) {
      setError(err instanceof TypeError ? t('error.network') : err.message) // TypeError = réseau indisponible
    } finally {
      setSending(false)
    }
  }

  const goNow = () => {
    clearInterval(timer.current)
    window.location.href = tier.paymentLink
  }

  return (
    <div className="modal-backdrop" onClick={reservation ? undefined : onClose}>
      <div
        className="modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="modal-title"
        onClick={(e) => e.stopPropagation()}
        style={{ '--tier': tier.color }}
      >
        {!reservation && <button className="modal__close" onClick={onClose} aria-label={t('modal.close')}>×</button>}
        <span className="tier-label">{tier.name}</span>

        {reservation ? (
          <div className="reserved" role="status" aria-live="polite">
            <h2 id="modal-title">✅ {t(reservation.quantity > 1 ? 'reserved.title.many' : 'reserved.title.one')}</h2>
            <p className="reserved__nums">{reservation.numbers.join(' · ')}</p>
            <p>
              {t('reserved.reference')}{t('colon')} <strong>{reservation.reference}</strong> · {t('reserved.total')}{t('colon')} <strong>{formatFCFA(reservation.total)}</strong>
            </p>
            <div className="reserved__step">
              {t('reserved.step')}
              <br />
              • {t('reserved.sameNameA')} <strong>{t('reserved.sameNameB')}</strong>{t('colon')} <strong className="reserved__name">{reservation.name}</strong>
              <br />
              • {t('reserved.payWith')} <strong className="reserved__name">{reservation.phone}</strong>
              <br />
              • {t('reserved.quantity')} <strong>{reservation.quantity}</strong> ({t('reserved.total').toLowerCase()} {formatFCFA(reservation.total)}).
            </div>
            <p className="form__hint">
              {reservation.emailed ? t('reserved.emailSent', { email: reservation.email }) + ' ' : ''}
              {t(reservation.quantity > 1 ? 'reserved.next.many' : 'reserved.next.one')}
            </p>
            <p className="reserved__countdown">
              {t(seconds > 1 ? 'reserved.countdown.many' : 'reserved.countdown.one', { n: seconds })}
            </p>
            <button className="btn btn--primary btn--block" onClick={goNow}>{t('reserved.goNow')}</button>
          </div>
        ) : (
          <>
            <h2 id="modal-title">{t('modal.title')}</h2>
            <form onSubmit={submit} className="form" noValidate>
              <label>
                {t('form.name')}
                <input value={form.name} onChange={update('name')} placeholder={t('form.namePlaceholder')} autoFocus autoComplete="name" />
              </label>
              <label>
                {t('form.phone')}
                <input value={form.phone} onChange={update('phone')} placeholder="+237 6XX XXX XXX" inputMode="tel" type="tel" autoComplete="tel" aria-describedby="phone-hint" />
                <small id="phone-hint" className="field-hint">{t('form.phoneHint')}</small>
              </label>
              <label>
                {t('form.email')}
                <input type="email" value={form.email} onChange={update('email')} placeholder={t('form.emailPlaceholder')} autoComplete="email" />
              </label>

              <div className="qty">
                <span>{t('form.quantity')}</span>
                <div className="qty__control">
                  <button type="button" onClick={() => setQty(form.quantity - 1)} aria-label={t('form.less')}>−</button>
                  <output>{form.quantity}</output>
                  <button type="button" onClick={() => setQty(form.quantity + 1)} aria-label={t('form.more')}>+</button>
                </div>
              </div>

              {remaining != null && remaining <= MAX_TICKETS_PER_ORDER && (
                <p className="form__hint">{t('form.left', { n: remaining })}</p>
              )}

              <div className="total">
                <span>{t('form.total')}</span>
                <strong>{formatFCFA(tier.price * form.quantity)}</strong>
              </div>

              {error && <p className="form__error" role="alert">{error}</p>}

              <button className="btn btn--primary btn--block" disabled={sending}>
                {sending ? t('form.sending') : t('form.submit')}
              </button>
              <p className="form__hint">
                {t('form.hint')}
              </p>
            </form>
          </>
        )}
      </div>
    </div>
  )
}
