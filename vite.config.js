import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import fs from 'node:fs'

// Sécurité : les données de ventes ne doivent JAMAIS partir dans le build.
// Sinon, un envoi FTP de dist/ écraserait le registre des ventes du serveur.
const excludeSalesData = () => ({
  name: 'exclude-sales-data',
  apply: 'build',
  closeBundle() {
    for (const dir of ['dist/api/data', 'dist/api/.data']) {
      if (fs.existsSync(dir)) {
        fs.rmSync(dir, { recursive: true })
        console.warn(`⚠ ${dir} retiré du build (données locales, ne pas déployer).`)
      }
    }
  },
})

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), excludeSalesData()],
  server: {
    // En développement, /api est envoyé au serveur PHP local (npm run api)
    proxy: { '/api': 'http://localhost:8000' },
  },
})
