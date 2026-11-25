import { useI18n } from '../i18n.jsx'

export default function Copyright() {
  const { t } = useI18n()
  return <p className="copyright">{t('copyright', { year: new Date().getFullYear() })}</p>
}
