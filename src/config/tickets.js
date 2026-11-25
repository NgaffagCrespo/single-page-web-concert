// =====================================================================
//  LES 4 GAMMES DE BILLETS
//  -> `paymentLink` / `reference` : lien de paiement Maviance de la gamme.
//  Les billets PDF (modèles public/templates/, numéro, QR code) sont générés
//  par le serveur : public/api/lib/ticket_pdf.php
// =====================================================================

export const TICKET_TIERS = [
  {
    id: 'standard',
    name: 'Standard',
    price: 10000,
    color: '#e8501b',
    perks: [], // avantages de la gamme (à compléter)
    reference: 'SEPPO4',
    paymentLink: 'https://goto.maviance.info/v1/UjWLUt6Dg',
  },
  {
    id: 'vip',
    name: 'Pass VIP',
    price: 25000,
    color: '#d99a1e',
    perks: [], // avantages de la gamme (à compléter)
    reference: 'SEPPO3',
    paymentLink: 'https://goto.maviance.info/v1/ARpxUp6vg',
  },
  {
    id: 'vvip',
    name: 'Pass VVIP',
    price: 50000,
    color: '#3f9b2f',
    perks: [], // avantages de la gamme (à compléter)
    reference: 'SEPPO2',
    paymentLink: 'https://goto.maviance.info/v1/PSLd8p6vg',
  },
  {
    id: 'gold',
    name: 'Pass Gold',
    price: 100000,
    color: '#f2b705',
    perks: [], // avantages de la gamme (à compléter)
    reference: 'SEPPO1',
    paymentLink: 'https://goto.maviance.info/v1/ArBwypeDg',
  },
]

export const MAX_TICKETS_PER_ORDER = 10

export const getTier = (id) => TICKET_TIERS.find((t) => t.id === id)

export const formatFCFA = (amount) =>
  new Intl.NumberFormat('fr-FR').format(amount).replace(/[\u202f\u00a0]/g, '.') + ' FCFA'
