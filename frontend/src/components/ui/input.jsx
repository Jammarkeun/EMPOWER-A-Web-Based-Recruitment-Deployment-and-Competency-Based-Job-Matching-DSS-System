import * as React from 'react'
import { Eye, EyeOff } from 'lucide-react'
import { cn } from '@/lib/utils'
import { toMobileDigits } from '@/lib/validation'

const Input = React.forwardRef(({ className, type, ...props }, ref) => (
  <input
    type={type}
    ref={ref}
    className={cn(
      'flex h-11 w-full rounded-control border border-transparent bg-inset px-4 py-2 text-sm transition-colors',
      'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-brand focus-visible:bg-card focus-visible:ring-1 focus-visible:ring-brand',
      'disabled:cursor-not-allowed disabled:opacity-50',
      'aria-[invalid=true]:border-destructive',
      className
    )}
    {...props}
  />
))
Input.displayName = 'Input'

/**
 * A password input with a show/hide toggle.
 *
 * Everything passed in - including the id, aria-invalid, and aria-describedby
 * that Field adds - lands on the <input> itself, so the label and any error
 * belong to the field a screen reader is on rather than to a wrapper around it.
 * Pass visible and onVisibleChange when another field should follow the same
 * toggle; otherwise it keeps its own.
 */
const PasswordInput = React.forwardRef(({ className, visible, onVisibleChange, ...props }, ref) => {
  const [ownVisible, setOwnVisible] = React.useState(false)
  const shown = visible ?? ownVisible

  const toggle = () => {
    setOwnVisible(!shown)
    onVisibleChange?.(!shown)
  }

  return (
    <div className="relative">
      <Input ref={ref} {...props} type={shown ? 'text' : 'password'} className={cn('pr-10', className)} />
      {/*
        Lets the user check a mistyped password rather than clearing the field
        and starting again. Kept out of the tab order so it never sits between
        the password field and the submit button.
      */}
      <button
        type="button"
        onClick={toggle}
        className="absolute right-1 top-0 flex h-11 w-10 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground"
        aria-label={shown ? 'Hide password' : 'Show password'}
        aria-pressed={shown}
        tabIndex={-1}
      >
        {shown ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
      </button>
    </div>
  )
})
PasswordInput.displayName = 'PasswordInput'

/**
 * A number field that only takes the kind of number it is for.
 *
 * A plain number box accepts "e", "+" and "-" as you type, and when what is
 * typed is not a number it reports an empty value, so "12e" was quietly sent
 * as blank. Here the keys a field can never use are refused: exponents always,
 * a minus sign where the minimum is 0 or more, and a decimal point where the
 * step is a whole number. Scrolling over a focused field no longer nudges its
 * value either. The server still checks everything.
 */
const NumberInput = React.forwardRef(({ min, step = 'any', onKeyDown, onWheel, ...props }, ref) => {
  const wholeOnly = step !== 'any' && Number.isInteger(Number(step))
  const noNegative = min !== undefined && min !== null && Number(min) >= 0

  function refuse(event) {
    const key = event.key
    if (key === 'e' || key === 'E' || key === '+') event.preventDefault()
    if (key === '-' && noNegative) event.preventDefault()
    if ((key === '.' || key === ',') && wholeOnly) event.preventDefault()
    onKeyDown?.(event)
  }

  return (
    <Input
      ref={ref}
      type="number"
      inputMode={wholeOnly ? 'numeric' : 'decimal'}
      min={min}
      step={step}
      onKeyDown={refuse}
      onWheel={(event) => {
        event.currentTarget.blur()
        onWheel?.(event)
      }}
      {...props}
    />
  )
})
NumberInput.displayName = 'NumberInput'

/**
 * A telephone number, kept as text so "0917…" keeps its leading zero and
 * "+63" its plus. Only the characters a number is written with are kept as
 * you type, and a plus only at the start; the server checks the digit count.
 */
const PhoneInput = React.forwardRef(({ onChange, ...props }, ref) => (
  <Input
    ref={ref}
    type="tel"
    inputMode="tel"
    autoComplete="tel"
    maxLength={25}
    onChange={(event) => {
      const raw = event.target.value
      const kept = raw.replace(/[^0-9+()\-. ]/g, '')
      event.target.value = kept.charAt(0) + kept.slice(1).replace(/\+/g, '')
      onChange?.(event)
    }}
    {...props}
  />
))
PhoneInput.displayName = 'PhoneInput'

/**
 * A person's own mobile number: digits only, and typing stops at 11, the
 * length of a Philippine mobile number (09XXXXXXXXX). A pasted "+63 917 123
 * 4567" or "0917 123 4567" becomes 09171234567, and a number saved in either
 * form before shows that way too. No maxLength attribute: the browser would
 * cut a pasted "+63 …" short before it could be converted.
 */
const MobileInput = React.forwardRef(({ onChange, value, ...props }, ref) => (
  <Input
    ref={ref}
    type="tel"
    inputMode="numeric"
    autoComplete="tel-national"
    placeholder="09XXXXXXXXX"
    value={toMobileDigits(value)}
    onChange={(event) => {
      event.target.value = toMobileDigits(event.target.value)
      onChange?.(event)
    }}
    {...props}
  />
))
MobileInput.displayName = 'MobileInput'

/**
 * A part of a person's name. Digits and symbols are dropped as they are typed
 * or pasted - a name never needs them - leaving letters (ñ and accents
 * included) and the spaces, periods, apostrophes, and hyphens real names use.
 * The same rule as App\Rules\PersonName, which the server applies regardless.
 */
const NameInput = React.forwardRef(({ onChange, ...props }, ref) => (
  <Input
    ref={ref}
    maxLength={100}
    onChange={(event) => {
      event.target.value = event.target.value.replace(/[^\p{L}\p{M} .'’-]/gu, '')
      onChange?.(event)
    }}
    {...props}
  />
))
NameInput.displayName = 'NameInput'

export { Input, MobileInput, NameInput, NumberInput, PasswordInput, PhoneInput }
