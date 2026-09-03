import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'aicrm.token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = tokenStore.get()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    // An expired or revoked token should drop the user back to the login screen
    // rather than leaving them on a page that silently fails to load.
    if (error.response?.status === 401 && !error.config?.url?.includes('auth/login')) {
      tokenStore.clear()

      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }
    }

    return Promise.reject(error)
  },
)

/** Pulls Laravel's validation messages into a flat, displayable shape. */
export function validationErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {}

  const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors

  if (!errors) return {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

export function errorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (!axios.isAxiosError(error)) return fallback

  const data = error.response?.data as { message?: string } | undefined
  const first = Object.values(validationErrors(error))[0]

  return first ?? data?.message ?? fallback
}
