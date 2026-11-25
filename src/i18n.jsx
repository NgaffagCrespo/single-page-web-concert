import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'

// =====================================================================
//  Langues de la page publique : français (par défaut) et anglais.
//  Le site s'ouvre dans la langue du navigateur (fr -> français,
//  en -> anglais, autre -> français) ; le choix du client est mémorisé.
// =====================================================================

export const LANGS = ['fr', 'en']
const STORAGE_KEY = 'seppo_lang'

const MESSAGES = {
  fr: {
    'meta.title': "Seppo – Concert Ndol'a Mbalè",
    'meta.description': "Seppo – Concert 100% Live « Ndol'a Mbalè » – 30 décembre 2026, Falaise Diamond de Bonanjo, Douala. Réservez vos billets en ligne.",
    'lang.label': 'Langue',
    'colon': ' :', // espace avant les deux-points en français
    'patronage': 'Sous le haut patronage de Monsieur le Gouverneur de la Région du Littoral',
    'hero.doors': 'À partir de 19H',
    'hero.buy': 'Acheter mes billets',
    'hero.posterAlt': 'Affiche du concert {artist}',
    'countdown.label': 'Compte à rebours avant le concert',
    'countdown.days': 'Jours',
    'countdown.hours': 'Heures',
    'countdown.minutes': 'Min',
    'countdown.seconds': 'Sec',
    'tickets.title': 'Billetterie',
    'tickets.lead': 'Choisissez votre gamme de billet – tickets disponibles',
    'tickets.choose': 'Choisir',
    'tickets.soldOut': 'Complet',
    'form.left': 'Il ne reste que {n} billet(s) dans cette catégorie.',
    'how.title': 'Comment ça marche ?',
    'how.1': 'Choisissez votre gamme, le nombre de billets, et indiquez vos coordonnées : vos billets sont réservés.',
    'how.2': 'Payez via notre partenaire Maviance (MTN Mobile Money / Orange Money), en indiquant le même nom et la même quantité.',
    'how.3': 'Dès la confirmation du paiement, vous recevez vos billets par email. Vous pouvez aussi passer récupérer votre billet physique.',
    'contact.title': 'Contact',
    'partners.title': 'Nos partenaires',
    'copyright': '© {year} Lc_Tech – Tous droits réservés',
    // Fenêtre d'achat
    'modal.close': 'Fermer',
    'modal.title': 'Réserver mes billets',
    'form.name': 'Nom complet (inscrit sur le billet) *',
    'form.namePlaceholder': 'Ex : Jean Ndongo',
    'form.phone': 'Numéro de paiement *',
    'form.phoneHint': 'Le numéro MTN Mobile Money ou Orange Money avec lequel vous allez effectuer le paiement.',
    'reserved.payWith': 'payez avec le numéro :',
    'form.email': 'E-mail (recommandé : vos billets y seront envoyés)',
    'form.emailPlaceholder': 'vous@exemple.com',
    'form.quantity': 'Nombre de billets',
    'form.less': 'Moins',
    'form.more': 'Plus',
    'form.total': 'Total',
    'form.submit': 'Réserver et payer',
    'form.sending': 'Réservation en cours…',
    'form.hint': 'Vos billets seront réservés, puis vous serez redirigé vers notre partenaire de paiement Maviance. Ils vous seront envoyés par email dès la confirmation du paiement.',
    'error.name': 'Indiquez votre nom complet.',
    'error.phoneMissing': 'Indiquez votre numéro de téléphone.',
    'error.phone': 'Numéro de téléphone invalide (9 chiffres minimum, ex : 690 00 00 00).',
    'error.noLink': "Le lien de paiement de cette gamme n'est pas encore configuré.",
    'error.generic': 'La réservation a échoué. Réessayez dans un instant.',
    'error.network': 'Connexion impossible. Vérifiez votre connexion internet et réessayez.',
    // Réservation confirmée
    'reserved.title.one': 'Votre billet est réservé',
    'reserved.title.many': 'Vos billets sont réservés',
    'reserved.reference': 'Référence',
    'reserved.total': 'Total',
    'reserved.step': 'Vous allez procéder au paiement sur la page Maviance. Sur cette page :',
    'reserved.sameNameA': 'indiquez le',
    'reserved.sameNameB': 'même nom',
    'reserved.quantity': 'indiquez la quantité :',
    'reserved.emailSent': 'Un email de confirmation vous a été envoyé à {email}.',
    'reserved.next.one': 'Vous recevrez votre billet par email sous peu, dès la confirmation de votre paiement. Vous pouvez également passer récupérer votre billet physique.',
    'reserved.next.many': 'Vous recevrez vos billets par email sous peu, dès la confirmation de votre paiement. Vous pouvez également passer récupérer votre billet physique.',
    'reserved.countdown.one': 'Redirection vers le paiement dans {n} seconde…',
    'reserved.countdown.many': 'Redirection vers le paiement dans {n} secondes…',
    'reserved.goNow': 'Aller au paiement maintenant',
  },
  en: {
    'meta.title': "Seppo – Ndol'a Mbalè Concert",
    'meta.description': "Seppo – 100% Live Concert « Ndol'a Mbalè » – December 30, 2026, Falaise Diamond, Bonanjo, Douala. Book your tickets online.",
    'lang.label': 'Language',
    'colon': ':',
    'patronage': 'Under the high patronage of the Governor of the Littoral Region',
    'hero.doors': 'From 7 PM',
    'hero.buy': 'Buy my tickets',
    'hero.posterAlt': '{artist} concert poster',
    'countdown.label': 'Countdown to the concert',
    'countdown.days': 'Days',
    'countdown.hours': 'Hours',
    'countdown.minutes': 'Min',
    'countdown.seconds': 'Sec',
    'tickets.title': 'Tickets',
    'tickets.lead': 'Choose your ticket category – tickets available',
    'tickets.choose': 'Choose',
    'tickets.soldOut': 'Sold out',
    'form.left': 'Only {n} ticket(s) left in this category.',
    'how.title': 'How does it work?',
    'how.1': 'Choose your category, the number of tickets, and enter your details: your tickets are reserved.',
    'how.2': 'Pay through our partner Maviance (MTN Mobile Money / Orange Money), using the same name and the same quantity.',
    'how.3': 'As soon as your payment is confirmed, you receive your tickets by email. You can also come and collect your physical ticket.',
    'contact.title': 'Contact',
    'partners.title': 'Our partners',
    'copyright': '© {year} Lc_Tech – All rights reserved',
    // Purchase window
    'modal.close': 'Close',
    'modal.title': 'Book my tickets',
    'form.name': 'Full name (printed on the ticket) *',
    'form.namePlaceholder': 'E.g. Jean Ndongo',
    'form.phone': 'Payment phone number *',
    'form.phoneHint': 'The MTN Mobile Money or Orange Money number you will use to make the payment.',
    'reserved.payWith': 'pay with the number:',
    'form.email': 'Email (recommended: your tickets will be sent there)',
    'form.emailPlaceholder': 'you@example.com',
    'form.quantity': 'Number of tickets',
    'form.less': 'Less',
    'form.more': 'More',
    'form.total': 'Total',
    'form.submit': 'Book and pay',
    'form.sending': 'Booking…',
    'form.hint': 'Your tickets will be reserved, then you will be redirected to our payment partner Maviance. They will be sent to you by email as soon as the payment is confirmed.',
    'error.name': 'Please enter your full name.',
    'error.phoneMissing': 'Please enter your phone number.',
    'error.phone': 'Invalid phone number (at least 9 digits, e.g. 690 00 00 00).',
    'error.noLink': 'The payment link for this category is not configured yet.',
    'error.generic': 'The booking failed. Please try again in a moment.',
    'error.network': 'Unable to connect. Check your internet connection and try again.',
    // Booking confirmed
    'reserved.title.one': 'Your ticket is reserved',
    'reserved.title.many': 'Your tickets are reserved',
    'reserved.reference': 'Reference',
    'reserved.total': 'Total',
    'reserved.step': 'You are about to pay on the Maviance page. On that page:',
    'reserved.sameNameA': 'enter the',
    'reserved.sameNameB': 'same name',
    'reserved.quantity': 'enter the quantity:',
    'reserved.emailSent': 'A confirmation email has been sent to {email}.',
    'reserved.next.one': 'You will receive your ticket by email shortly, as soon as your payment is confirmed. You can also come and collect your physical ticket.',
    'reserved.next.many': 'You will receive your tickets by email shortly, as soon as your payment is confirmed. You can also come and collect your physical ticket.',
    'reserved.countdown.one': 'Redirecting to payment in {n} second…',
    'reserved.countdown.many': 'Redirecting to payment in {n} seconds…',
    'reserved.goNow': 'Go to payment now',
  },
}

/** Langue du navigateur : français ou anglais (français par défaut). */
export const detectBrowserLang = () => {
  const prefs = typeof navigator !== 'undefined' ? [...(navigator.languages || []), navigator.language] : []
  for (const l of prefs) {
    const code = String(l || '').slice(0, 2).toLowerCase()
    if (LANGS.includes(code)) return code
  }
  return 'fr'
}

const readSaved = () => {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return LANGS.includes(v) ? v : null
  } catch {
    return null // stockage indisponible (navigation privée…)
  }
}

const I18nContext = createContext(null)

export function I18nProvider({ children }) {
  const [lang, setLangState] = useState(() => readSaved() || detectBrowserLang())

  const setLang = useCallback((l) => {
    if (!LANGS.includes(l)) return
    setLangState(l)
    try {
      localStorage.setItem(STORAGE_KEY, l)
    } catch {
      /* ignore */
    }
  }, [])

  // Langue du document, titre et description (accessibilité et moteurs de recherche)
  useEffect(() => {
    document.documentElement.lang = lang
    document.title = MESSAGES[lang]['meta.title']
    document.querySelector('meta[name="description"]')?.setAttribute('content', MESSAGES[lang]['meta.description'])
  }, [lang])

  const t = useCallback(
    (key, vars = {}) => {
      const s = MESSAGES[lang][key] ?? MESSAGES.fr[key] ?? key
      return s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
    },
    [lang],
  )

  const value = useMemo(() => ({ lang, setLang, t }), [lang, setLang, t])
  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>
}

export const useI18n = () => useContext(I18nContext)

/** Sélecteur de langue « FR | EN » */
export function LangSwitch() {
  const { lang, setLang, t } = useI18n()
  return (
    <div className="lang-switch" role="group" aria-label={t('lang.label')}>
      {LANGS.map((l) => (
        <button
          key={l}
          type="button"
          className={l === lang ? 'on' : ''}
          aria-pressed={l === lang}
          lang={l}
          onClick={() => setLang(l)}
        >
          {l.toUpperCase()}
        </button>
      ))}
    </div>
  )
}
