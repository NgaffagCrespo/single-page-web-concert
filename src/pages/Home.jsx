import { useEffect, useState } from 'react'
import { EVENT } from '../config/event'
import { TICKET_TIERS, formatFCFA } from '../config/tickets'
import Countdown from '../components/Countdown'
import PurchaseModal from '../components/PurchaseModal'
import Copyright from '../components/Copyright'
import { useI18n, LangSwitch } from '../i18n.jsx'

const AVAILABILITY_URL = import.meta.env.VITE_AVAILABILITY_URL || '/api/disponibilites.php'

export default function Home() {
  const [selectedTier, setSelectedTier] = useState(null)
  const [availability, setAvailability] = useState(null) // places restantes par catégorie
  const { t } = useI18n()

  // Places restantes (si le serveur ne répond pas, on n'affiche rien de plus : il vérifie de toute façon)
  useEffect(() => {
    fetch(AVAILABILITY_URL)
      .then((r) => (r.ok ? r.json() : null))
      .then((d) => d && setAvailability(d))
      .catch(() => {})
  }, [])
  const remainingOf = (id) => availability?.[id]?.remaining

  return (
    <>
      <div className="topbar">
        <p className="patronage">{t('patronage')}</p>
        <LangSwitch />
      </div>

      <header className="hero" style={{ '--poster': `url(${EVENT.poster})` }}>
        <div className="hero__inner">
          <div className="hero__text">
            <p className="hero__artist">{EVENT.artist}</p>
            <h1 className="hero__title">
              Concert
              <span className="live-badge">100% Live</span>
            </h1>
            <p className="hero__subtitle">{EVENT.subtitle}</p>
            <p className="hero__date">{EVENT.dateLabel}</p>
            <p className="hero__venue">
              <span aria-hidden>📍</span> {EVENT.city} – <strong>{EVENT.venue}</strong>
            </p>
            <p className="hero__doors">{t('hero.doors')}</p>
            <Countdown target={EVENT.date} />
            <a href="#billets" className="btn btn--primary">{t('hero.buy')}</a>
          </div>
          <img className="hero__poster" src={EVENT.poster} alt={t('hero.posterAlt', { artist: EVENT.artist })} />
        </div>
      </header>

      <main>
        <section id="billets" className="section">
          <h2 className="section__title">{t('tickets.title')}</h2>
          <p className="section__lead">{t('tickets.lead')}</p>

          <div className="tiers">
            {TICKET_TIERS.map((tier) => (
              <article key={tier.id} className={'tier' + (remainingOf(tier.id) === 0 ? ' tier--soldout' : '')} style={{ '--tier': tier.color }}>
                <span className="tier-label">{tier.name}</span>
                <p className="tier__price">
                  {formatFCFA(tier.price).replace(' FCFA', '')}
                  <sup>FCFA</sup>
                </p>
                {tier.perks.length > 0 && (
                  <ul className="tier__perks">
                    {tier.perks.map((p) => <li key={p}>{p}</li>)}
                  </ul>
                )}
                {remainingOf(tier.id) === 0 ? (
                  <button className="btn btn--tier" disabled>{t('tickets.soldOut')}</button>
                ) : (
                  <button className="btn btn--tier" onClick={() => setSelectedTier(tier)}>
                    {t('tickets.choose')}
                  </button>
                )}
              </article>
            ))}
          </div>
        </section>

        <section className="section info">
          <div>
            <h3>{t('how.title')}</h3>
            <ol>
              <li>{t('how.1')}</li>
              <li>{t('how.2')}</li>
              <li>{t('how.3')}</li>
            </ol>
          </div>
          <div>
            <h3>{t('contact.title')}</h3>
            <p>
              <a href={`tel:${EVENT.contactPhone}`} className="contact-link">📞 {EVENT.contactPhoneLabel}</a>
            </p>
            <p>{EVENT.city} – {EVENT.venue}<br />{t('hero.doors')}</p>
          </div>
        </section>
      </main>

      <footer className="partners">
        <p className="partners__title">{t('partners.title')}</p>
        <div className="partners__logos">
          {EVENT.partners.map((p) =>
            p.logo ? (
              <img key={p.name} src={p.logo} alt={p.name} title={p.name} loading="lazy" />
            ) : (
              <span key={p.name} className="partners__name">{p.name}</span>
            ),
          )}
        </div>
      </footer>
      <Copyright />

      {selectedTier && (
        <PurchaseModal tier={selectedTier} remaining={remainingOf(selectedTier.id)} onClose={() => setSelectedTier(null)} />
      )}
    </>
  )
}
