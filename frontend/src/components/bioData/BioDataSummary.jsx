import * as React from 'react'
import { formatDate, cn } from '@/lib/utils'
import { BIO_DATA_LABELS, optionLabel } from '@/lib/bioData'

/**
 * A Bio-Data, read-only, in the order of the form and the PDF.
 *
 * Used for the applicant's review step and what they sent, and for HR's
 * review. `changes` (HR only) marks each declared value that differs from the
 * profile, with what is on file now, so the reviewer sees exactly what
 * verifying would write. A blank is shown as "Not given", never as a guess.
 */
export default function BioDataSummary({ profile, form, options, changes = null, className }) {
  const fullName = [profile?.first_name, profile?.middle_name, profile?.last_name, profile?.suffix]
    .filter(Boolean)
    .join(' ')

  const declared = (field, value) => ({
    label: BIO_DATA_LABELS[field],
    value,
    onFile: changes?.[field] ? formatOnFile(field, changes[field][0], options) : undefined,
  })

  return (
    <div className={cn('space-y-5', className)}>
      <Section title="Personal information">
        <Facts
          rows={[
            { label: 'Name', value: fullName },
            { label: 'Date of birth', value: profile?.birth_date ? formatDate(profile.birth_date) : null },
            { label: 'Age', value: profile?.age ? `${profile.age} years` : null },
            declared('sex', optionLabel(options?.sex, form.sex)),
            declared('civil_status', optionLabel(options?.civil_status, form.civil_status)),
            declared('nationality', form.nationality),
            declared('height_cm', form.height_cm ? `${Number(form.height_cm)} cm` : null),
            declared('weight_kg', form.weight_kg ? `${Number(form.weight_kg)} kg` : null),
          ]}
        />
      </Section>

      <Section title="Address and contact">
        <Facts
          rows={[
            { label: 'Present address', value: profile?.present_address, wide: true },
            { ...declared('provincial_address', form.provincial_address), wide: true },
            { label: 'Contact number', value: profile?.contact_number },
            { label: 'Email address', value: profile?.email },
          ]}
        />
      </Section>

      <Section title="Educational background">
        {form.educations?.length ? (
          <ul className="space-y-2">
            {form.educations.map((row, index) => (
              <li key={index} className="rounded-md border px-3 py-2 text-sm">
                <p className="font-medium">{row.school_name || 'School not given'}</p>
                <p className="text-xs text-muted-foreground">
                  {[
                    optionLabel(options?.education_level, row.education_level),
                    row.course_program,
                    row.graduation_year ? `Year ${row.graduation_year}` : null,
                    row.honors,
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                </p>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">No schools listed.</p>
        )}
      </Section>

      <Section title="Employment record">
        {form.experiences?.length ? (
          <ul className="space-y-2">
            {form.experiences.map((row, index) => (
              <li key={index} className="rounded-md border px-3 py-2 text-sm">
                <p className="font-medium">
                  {row.position_title || 'Position not given'}
                  <span className="font-normal text-muted-foreground"> at </span>
                  {row.company_name || 'employer not given'}
                </p>
                <p className="text-xs text-muted-foreground">
                  {row.start_date ? formatDate(row.start_date) : 'Start not given'} –{' '}
                  {row.end_date ? formatDate(row.end_date) : 'present'}
                </p>
                {row.responsibilities && <p className="mt-1 text-xs">{row.responsibilities}</p>}
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-muted-foreground">No previous employment listed.</p>
        )}
      </Section>
    </div>
  )
}

function formatOnFile(field, value, options) {
  if (value === null || value === undefined || value === '') return 'nothing on file'
  if (field === 'sex') return optionLabel(options?.sex, value)
  if (field === 'civil_status') return optionLabel(options?.civil_status, value)
  if (field === 'height_cm') return `${Number(value)} cm`
  if (field === 'weight_kg') return `${Number(value)} kg`
  return value
}

function Section({ title, children }) {
  return (
    <section>
      <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{title}</h3>
      {children}
    </section>
  )
}

function Facts({ rows }) {
  return (
    <dl className="grid gap-x-4 gap-y-3 sm:grid-cols-2">
      {rows.map((row) => (
        <div key={row.label} className={row.wide ? 'sm:col-span-2' : undefined}>
          <dt className="text-xs text-muted-foreground">{row.label}</dt>
          <dd className={cn('text-sm', row.value ? 'font-medium' : 'text-muted-foreground')}>
            {row.value || 'Not given'}
          </dd>
          {row.onFile !== undefined && (
            <dd className="mt-0.5 text-xs text-warning">Differs from profile — on file: {row.onFile}</dd>
          )}
        </div>
      ))}
    </dl>
  )
}
