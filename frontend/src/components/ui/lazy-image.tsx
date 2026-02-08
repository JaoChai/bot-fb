import { useState, useRef, useEffect, memo } from 'react';
import { ImageIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

const observerCallbacks = new Map<Element, (entry: IntersectionObserverEntry) => void>();
let sharedObserver: IntersectionObserver | null = null;

function getSharedObserver(): IntersectionObserver {
  if (!sharedObserver) {
    sharedObserver = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        const callback = observerCallbacks.get(entry.target);
        callback?.(entry);
      });
    }, { rootMargin: '200px' });
  }
  return sharedObserver;
}

interface LazyImageProps {
  src: string;
  alt: string;
  className?: string;
  placeholderClassName?: string;
  onClick?: () => void;
}

export const LazyImage = memo(function LazyImage({
  src,
  alt,
  className,
  placeholderClassName,
  onClick,
}: LazyImageProps) {
  const [isLoaded, setIsLoaded] = useState(false);
  const [isInView, setIsInView] = useState(false);
  const [hasError, setHasError] = useState(false);
  const [currentSrc, setCurrentSrc] = useState(src);
  const imgRef = useRef<HTMLDivElement>(null);

  // Reset states when src changes - handled via derived state
  if (src !== currentSrc) {
    setCurrentSrc(src);
    setIsLoaded(false);
    setHasError(false);
  }

  useEffect(() => {
    const el = imgRef.current;
    if (!el) return;

    const callback = (entry: IntersectionObserverEntry) => {
      if (entry.isIntersecting) {
        setIsInView(true);
        observerCallbacks.delete(el);
        getSharedObserver().unobserve(el);
      }
    };

    observerCallbacks.set(el, callback);
    getSharedObserver().observe(el);

    return () => {
      observerCallbacks.delete(el);
      getSharedObserver().unobserve(el);
    };
  }, []);

  return (
    <div ref={imgRef} className="relative">
      {/* Placeholder shown while loading or not in view */}
      {(!isInView || !isLoaded) && !hasError && (
        <div
          className={cn(
            'flex items-center justify-center bg-muted/30 animate-pulse rounded-lg',
            placeholderClassName || 'min-h-[120px] w-full'
          )}
        >
          <ImageIcon className="h-8 w-8 text-muted-foreground/50" />
        </div>
      )}

      {/* Error state */}
      {hasError && (
        <div
          className={cn(
            'flex flex-col items-center justify-center bg-muted/30 rounded-lg p-4',
            placeholderClassName || 'min-h-[120px] w-full'
          )}
        >
          <ImageIcon className="h-8 w-8 text-muted-foreground/50 mb-2" />
          <span className="text-xs text-muted-foreground">ไม่สามารถโหลดรูปได้</span>
        </div>
      )}

      {/* Actual image - only load when in view */}
      {isInView && !hasError && (
        <img
          src={src}
          alt={alt}
          className={cn(
            className,
            'transition-opacity duration-300',
            isLoaded ? 'opacity-100' : 'opacity-0 absolute inset-0'
          )}
          onClick={onClick}
          onLoad={() => setIsLoaded(true)}
          onError={() => setHasError(true)}
        />
      )}
    </div>
  );
});
