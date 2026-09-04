import { useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { documentApi } from '@/api/endpoints'
import { Badge, Button, EmptyState, Select, Spinner, cx } from '@/components/ui'
import { shortDate } from '@/lib/format'
import { useAuth } from '@/hooks/useAuth'
import type { DocumentFile } from '@/types'

const TYPES = [
  ['', 'No type'],
  ['proposal', 'Proposal'],
  ['quotation', 'Quotation'],
  ['contract', 'Contract'],
  ['specification', 'Specification'],
  ['other', 'Other'],
] as const

function readableSize(bytes: number | null): string {
  if (bytes === null) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export function Documents({ subjectType, subjectId }: { subjectType: string; subjectId: string }) {
  const { can } = useAuth()
  const queryClient = useQueryClient()
  const fileInput = useRef<HTMLInputElement>(null)
  const [documentType, setDocumentType] = useState('')
  const [isInternal, setIsInternal] = useState(false)

  const queryKey = ['documents', subjectType, subjectId]

  const { data: documents = [], isLoading } = useQuery({
    queryKey,
    queryFn: () => documentApi.list(subjectType, subjectId),
    enabled: can('document.view'),
  })

  const upload = useMutation({
    mutationFn: (file: File) => {
      const form = new FormData()
      form.append('file', file)
      form.append('subject_type', subjectType)
      form.append('subject_id', subjectId)
      if (documentType) form.append('document_type', documentType)
      if (isInternal) form.append('is_internal', '1')

      return documentApi.upload(form)
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey })
      if (fileInput.current) fileInput.current.value = ''
    },
  })

  const remove = useMutation({
    mutationFn: (id: string) => documentApi.remove(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey }),
  })

  if (!can('document.view')) {
    return <EmptyState message="You do not have access to documents." />
  }

  return (
    <div>
      {can('document.upload') && (
        <div className="mb-4 flex flex-wrap items-end gap-2 rounded-lg bg-slate-50 p-3">
          <div className="w-40">
            <span className="mb-1 block text-xs font-medium text-slate-700">Type</span>
            <Select value={documentType} onChange={(event) => setDocumentType(event.target.value)}>
              {TYPES.map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </Select>
          </div>

          <label className="flex items-center gap-1.5 pb-2 text-xs text-slate-700">
            <input
              type="checkbox"
              checked={isInternal}
              onChange={(event) => setIsInternal(event.target.checked)}
              className="size-4 rounded border-slate-300"
            />
            Internal only
          </label>

          <input
            ref={fileInput}
            type="file"
            className="hidden"
            onChange={(event) => {
              const file = event.target.files?.[0]
              if (file) upload.mutate(file)
            }}
          />

          <Button className="ml-auto" onClick={() => fileInput.current?.click()} disabled={upload.isPending}>
            {upload.isPending ? 'Uploading…' : '+ Upload file'}
          </Button>
        </div>
      )}

      {isLoading ? (
        <Spinner />
      ) : documents.length === 0 ? (
        <EmptyState message="No documents attached yet." />
      ) : (
        <ul className="divide-y divide-slate-100">
          {documents.map((doc: DocumentFile) => (
            <li key={doc.id} className="flex items-center gap-3 py-2.5">
              <span className="flex size-8 shrink-0 items-center justify-center rounded bg-slate-100 text-xs text-slate-500">
                ▤
              </span>
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 truncate text-sm font-medium text-slate-900">
                  {doc.name}
                  {doc.is_internal && <Badge tone="purple">Internal</Badge>}
                  {doc.document_type && <Badge tone="slate">{doc.document_type}</Badge>}
                </p>
                <p className="mt-0.5 text-xs text-slate-500">
                  {readableSize(doc.file_size)}
                  {doc.uploader && <span> · {doc.uploader.name}</span>}
                  <span> · {shortDate(doc.created_at)}</span>
                </p>
              </div>
              <Button variant="ghost" size="sm" onClick={() => void documentApi.download(doc)}>
                Download
              </Button>
              {can('document.delete') && (
                <Button
                  variant="ghost"
                  size="sm"
                  className={cx('text-red-600')}
                  onClick={() => remove.mutate(doc.id)}
                  disabled={remove.isPending}
                >
                  Delete
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
