import * as React from 'react'
import * as SelectPrimitive from '@radix-ui/react-select'
import { Check, ChevronDown, ChevronUp } from 'lucide-react'
import { cn } from '@/lib/utils'

const SelectRoot = SelectPrimitive.Root
const SelectGroup = SelectPrimitive.Group
const SelectValue = SelectPrimitive.Value

const SelectTrigger = React.forwardRef(({ className, children, ...props }, ref) => (
  <SelectPrimitive.Trigger
    ref={ref}
    className={cn(
      'flex h-11 w-full items-center justify-between rounded-control border border-transparent bg-inset px-4 py-2 text-sm transition-colors',
      'placeholder:text-muted-foreground focus:outline-none focus:border-brand focus:bg-card focus:ring-1 focus:ring-brand',
      'disabled:cursor-not-allowed disabled:opacity-50 aria-[invalid=true]:border-destructive',
      '[&>span]:line-clamp-1',
      className
    )}
    {...props}
  >
    {children}
    <SelectPrimitive.Icon asChild>
      <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground opacity-70" />
    </SelectPrimitive.Icon>
  </SelectPrimitive.Trigger>
))
SelectTrigger.displayName = SelectPrimitive.Trigger.displayName

const SelectScrollUpButton = React.forwardRef(({ className, ...props }, ref) => (
  <SelectPrimitive.ScrollUpButton
    ref={ref}
    className={cn('flex cursor-default items-center justify-center py-1 text-muted-foreground', className)}
    {...props}
  >
    <ChevronUp className="h-4 w-4" />
  </SelectPrimitive.ScrollUpButton>
))
SelectScrollUpButton.displayName = SelectPrimitive.ScrollUpButton.displayName

const SelectScrollDownButton = React.forwardRef(({ className, ...props }, ref) => (
  <SelectPrimitive.ScrollDownButton
    ref={ref}
    className={cn('flex cursor-default items-center justify-center py-1 text-muted-foreground', className)}
    {...props}
  >
    <ChevronDown className="h-4 w-4" />
  </SelectPrimitive.ScrollDownButton>
))
SelectScrollDownButton.displayName = SelectPrimitive.ScrollDownButton.displayName

const SelectContent = React.forwardRef(({ className, children, position = 'popper', ...props }, ref) => (
  <SelectPrimitive.Portal>
    <SelectPrimitive.Content
      ref={ref}
      className={cn(
        'relative z-50 max-h-96 min-w-[8rem] overflow-hidden rounded-row border border-border/70 bg-popover text-popover-foreground shadow-float',
        'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=top]:slide-in-from-bottom-2',
        position === 'popper' &&
          'data-[side=bottom]:translate-y-1 data-[side=left]:-translate-x-1 data-[side=right]:translate-x-1 data-[side=top]:-translate-y-1',
        className
      )}
      position={position}
      {...props}
    >
      <SelectScrollUpButton />
      <SelectPrimitive.Viewport
        className={cn(
          'p-1',
          position === 'popper' &&
            'h-[var(--radix-select-trigger-height)] w-full min-w-[var(--radix-select-trigger-width)]'
        )}
      >
        {children}
      </SelectPrimitive.Viewport>
      <SelectScrollDownButton />
    </SelectPrimitive.Content>
  </SelectPrimitive.Portal>
))
SelectContent.displayName = SelectPrimitive.Content.displayName

const SelectLabel = React.forwardRef(({ className, ...props }, ref) => (
  <SelectPrimitive.Label
    ref={ref}
    className={cn('px-2 py-1.5 text-xs font-semibold text-muted-foreground', className)}
    {...props}
  />
))
SelectLabel.displayName = SelectPrimitive.Label.displayName

const SelectItem = React.forwardRef(({ className, children, ...props }, ref) => (
  <SelectPrimitive.Item
    ref={ref}
    className={cn(
      'relative flex w-full cursor-default select-none items-center rounded-[10px] py-2 pl-8 pr-2 text-sm outline-none transition-colors',
      'focus:bg-inset-selected focus:text-accent-foreground data-[disabled]:pointer-events-none data-[disabled]:opacity-50',
      className
    )}
    {...props}
  >
    <span className="absolute left-2 flex h-3.5 w-3.5 items-center justify-center">
      <SelectPrimitive.ItemIndicator>
        <Check className="h-4 w-4 text-brand" />
      </SelectPrimitive.ItemIndicator>
    </span>
    <SelectPrimitive.ItemText>{children}</SelectPrimitive.ItemText>
  </SelectPrimitive.Item>
))
SelectItem.displayName = SelectPrimitive.Item.displayName

const SelectSeparator = React.forwardRef(({ className, ...props }, ref) => (
  <SelectPrimitive.Separator
    ref={ref}
    className={cn('-mx-1 my-1 h-px bg-muted', className)}
    {...props}
  />
))
SelectSeparator.displayName = SelectPrimitive.Separator.displayName

/**
 * Universal Select component.
 * Supports both Radix composition subcomponents (Select, SelectTrigger, etc.)
 * AND direct properties (placeholder, options, value, onChange) for ease of use across legacy views.
 */
const Select = React.forwardRef(
  (
    {
      className,
      children,
      placeholder,
      options,
      value,
      onChange,
      onValueChange,
      disabled,
      name,
      id,
      ...props
    },
    ref
  ) => {
    // If standard Radix composition children are passed (e.g. SelectTrigger), act as Root
    const hasCustomTrigger = React.Children.toArray(children).some(
      (child) => React.isValidElement(child) && child.type === SelectTrigger
    )

    if (hasCustomTrigger) {
      return (
        <SelectRoot value={value} onValueChange={onValueChange || onChange} disabled={disabled} {...props}>
          {children}
        </SelectRoot>
      )
    }

    // Adapt event-based onChange to Radix string value callback if needed
    const handleValueChange = (val) => {
      if (onValueChange) {
        onValueChange(val)
      }
      if (onChange) {
        onChange({ target: { name, id, value: val } })
      }
    }

    // Extract options from props or child <option> elements
    let optionItems = options || []
    if (!options && children) {
      optionItems = React.Children.toArray(children)
        .filter((child) => React.isValidElement(child) && child.type === 'option')
        .map((child) => ({
          value: child.props.value,
          label: child.props.children,
          disabled: child.props.disabled,
        }))
    }

    const currentValue = value !== undefined && value !== null ? String(value) : ''

    return (
      <SelectRoot value={currentValue} onValueChange={handleValueChange} disabled={disabled}>
        <SelectTrigger ref={ref} className={className} id={id} name={name} {...props}>
          <SelectValue placeholder={placeholder || 'Select option...'} />
        </SelectTrigger>
        <SelectContent>
          {placeholder && (
            <SelectItem value="" className="text-muted-foreground">
              {placeholder}
            </SelectItem>
          )}
          {optionItems.map((opt, idx) => (
            <SelectItem key={opt.value ?? idx} value={String(opt.value)} disabled={opt.disabled}>
              {opt.label ?? opt.value}
            </SelectItem>
          ))}
        </SelectContent>
      </SelectRoot>
    )
  }
)
Select.displayName = 'Select'

export {
  Select,
  SelectRoot,
  SelectGroup,
  SelectValue,
  SelectTrigger,
  SelectContent,
  SelectLabel,
  SelectItem,
  SelectSeparator,
  SelectScrollUpButton,
  SelectScrollDownButton,
}
