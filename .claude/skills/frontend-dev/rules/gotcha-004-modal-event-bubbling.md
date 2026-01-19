---
id: gotcha-004-modal-event-bubbling
title: Modal Event Bubbling
impact: MEDIUM
impactDescription: "Prevents modals from closing unexpectedly due to event propagation"
category: gotcha
tags: [modal, events, dialog, radix, ui]
relatedRules: []
---

## Why This Matters

Modals in BotFacebook use Radix UI's Dialog component, which closes when clicking the overlay. If click events from modal content bubble up to the overlay, the modal closes unexpectedly. This is frustrating for users filling out forms or clicking buttons inside modals.

Event bubbling is the DOM's default behavior where events propagate from the target element up through its ancestors.

## Bad Example

```tsx
// Problem: Click events bubble up to overlay
function ConfirmDialog({ onConfirm, onClose }) {
  return (
    <Dialog.Root>
      <Dialog.Portal>
        <Dialog.Overlay onClick={onClose} className="fixed inset-0 bg-black/50" />
        <Dialog.Content className="fixed top-1/2 left-1/2 ...">
          <h2>Confirm Action</h2>
          <button onClick={onConfirm}>
            Confirm {/* Click bubbles to overlay, closing modal! */}
          </button>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

// Problem 2: Form submission closes modal
function EditBotModal({ bot, onSave }) {
  return (
    <Dialog.Content>
      <form onSubmit={onSave}>
        <input name="name" defaultValue={bot.name} />
        <button type="submit">Save</button>
        {/* Submit event might bubble and trigger unwanted behaviors */}
      </form>
    </Dialog.Content>
  );
}
```

**Why it's wrong:**
- Click on "Confirm" button bubbles up to Dialog.Overlay
- Overlay's onClick handler fires, closing the modal
- User action is interrupted before completing
- Form submissions can have similar bubbling issues

## Good Example

```tsx
// Solution 1: Stop propagation on content wrapper
function ConfirmDialog({ onConfirm, onClose }) {
  return (
    <Dialog.Root>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/50" />
        <Dialog.Content
          className="fixed top-1/2 left-1/2 ..."
          onClick={(e) => e.stopPropagation()} // Prevent bubbling
        >
          <h2>Confirm Action</h2>
          <button onClick={onConfirm}>Confirm</button>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

// Solution 2: Use Radix's built-in close behavior
function ConfirmDialog({ onConfirm }) {
  return (
    <Dialog.Root>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/50" />
        <Dialog.Content className="fixed top-1/2 left-1/2 ...">
          <h2>Confirm Action</h2>
          <div className="flex gap-2">
            <Dialog.Close asChild>
              <button variant="outline">Cancel</button>
            </Dialog.Close>
            <button onClick={onConfirm}>Confirm</button>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

// Solution 3: Stop propagation on specific buttons
function EditBotModal({ bot, onSave }) {
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    e.stopPropagation(); // Prevent bubbling
    onSave(new FormData(e.currentTarget));
  };

  return (
    <Dialog.Content onClick={(e) => e.stopPropagation()}>
      <form onSubmit={handleSubmit}>
        <input name="name" defaultValue={bot.name} />
        <button type="submit">Save</button>
      </form>
    </Dialog.Content>
  );
}
```

**Why it's better:**
- `stopPropagation()` prevents event from reaching overlay
- Radix Dialog.Close handles closing correctly without bubbling issues
- Form submission stays contained within the modal
- User can interact with modal content without unexpected closures

## Project-Specific Notes

**Using Our Dialog Component:**
```tsx
// src/components/ui/dialog.tsx wraps Radix
import { Dialog, DialogContent, DialogClose } from '@/components/ui/dialog';

function MyModal() {
  return (
    <Dialog>
      <DialogContent>
        {/* Already has stopPropagation in our wrapper */}
        <form>...</form>
      </DialogContent>
    </Dialog>
  );
}
```

**Common Modal Locations:**
- `src/components/modals/` - Feature-specific modals
- `src/components/ui/dialog.tsx` - Base dialog component
- `src/components/ui/alert-dialog.tsx` - Confirmation dialogs

## References

- [Radix Dialog](https://www.radix-ui.com/primitives/docs/components/dialog)
- [Event Bubbling MDN](https://developer.mozilla.org/en-US/docs/Learn/JavaScript/Building_blocks/Events#event_bubbling)
