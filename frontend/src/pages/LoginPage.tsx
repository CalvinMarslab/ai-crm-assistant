import { useState, type FormEvent } from 'react'
import { useAuth } from '@/hooks/useAuth'
import { errorMessage } from '@/api/client'
import { Button, Card, Field, Input } from '@/components/ui'

export default function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [pending, setPending] = useState(false)

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    setError('')
    setPending(true)

    try {
      await login(email, password)
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to sign in.'))
    } finally {
      setPending(false)
    }
  }

  return (
    <div className="flex min-h-full items-center justify-center px-4 py-12">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <div className="mx-auto mb-3 flex size-11 items-center justify-center rounded-xl bg-brand-600 text-lg font-bold text-white">
            AI
          </div>
          <h1 className="text-xl font-semibold text-slate-900">AI CRM Assistant</h1>
          <p className="mt-1 text-sm text-slate-500">Sign in to see what needs your attention.</p>
        </div>

        <Card className="p-5">
          <form onSubmit={onSubmit} className="space-y-4">
            <Field label="Email" required>
              <Input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                autoComplete="username"
                required
                autoFocus
              />
            </Field>

            <Field label="Password" required>
              <Input
                type="password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                autoComplete="current-password"
                required
              />
            </Field>

            {error && (
              <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-200">{error}</p>
            )}

            <Button type="submit" className="w-full" disabled={pending}>
              {pending ? 'Signing in…' : 'Sign in'}
            </Button>
          </form>
        </Card>
      </div>
    </div>
  )
}
