import axios, { AxiosError } from 'axios'

const TOKEN_KEY = 'aicrm.token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token: string) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

export const api = axios.create({
  // Unset in development, where Vite proxies /api to the local backend and
  // keeps the browser on one origin. Set in production to point at the
  // deployed API, which lives on a different host.
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
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
    const status = error.response?.status
    const isLoginRequest = error.config?.url?.includes('auth/login') ?? false

    // An expired or revoked token should drop the user back to the login screen
    // rather than leaving them on a page that silently fails to load.
    if (status === 401 && !isLoginRequest) {
      tokenStore.clear()

      if (window.location.pathname !== '/login') {
        window.location.assign('/login')
      }

      return Promise.reject(error)
    }

    // 422 is handled at the form, beside the offending field. Everything else
    // needs to be said out loud.
    if (!isLoginRequest && status !== 422 && status !== 401) {
      announce(errorMessage(error))
    }

    return Promise.reject(error)
  },
)

/**
 * Errors that are not field validation surface as a notification, because a
 * mutation that fails silently reads to the user as "the button is broken".
 * Queries render their own ErrorState, so only mutations subscribe here.
 */
type ErrorListener = (message: string) => void

const errorListeners = new Set<ErrorListener>()

export function onApiError(listener: ErrorListener): () => void {
  errorListeners.add(listener)

  return () => errorListeners.delete(listener)
}

function announce(message: string): void {
  errorListeners.forEach((listener) => listener(message))
}

/** Pulls Laravel's validation messages into a flat, displayable shape. */
export function validationErrors(error: unknown): Record<string, string> {
  if (!axios.isAxiosError(error)) return {}

  const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors

  if (!errors) return {}

  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}

/**
 * Human wording per status. Laravel's own message is preferred when it is
 * meaningful, but framework strings like "Server Error" tell a salesperson
 * nothing about what to do next.
 */
export function errorMessage(error: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (!axios.isAxiosError(error)) return fallback

  if (error.code === 'ERR_NETWORK') {
    return 'Cannot reach the server. Check your connection and try again.'
  }

  const status = error.response?.status
  const data = error.response?.data as { message?: string } | undefined
  const firstValidation = Object.values(validationErrors(error))[0]

  if (firstValidation) return firstValidation

  switch (status) {
    case 403:
      return 'You do not have permission to do that.'
    case 404:
      return 'That record no longer exists, or you do not have access to it.'
    case 409:
      return 'That change conflicts with the current state of the record.'
    case 429:
      return 'Too many attempts. Please wait a moment and try again.'
    case 500:
    case 502:
    case 503:
      return 'The server ran into a problem. Nothing was saved — please try again.'
    default:
      return data?.message && data.message !== 'Server Error' ? data.message : fallback
  }
}
