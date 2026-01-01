import { useState, useRef, useEffect, memo } from 'react';
import { ImageIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface LazyImageProps {
  src: string;
  alt: string;
  className?: string;
  placeholderClassName?: string;
  onClick?: () => void;
}

/**
 * LazyImage component - loads images only when they enter the viewport
 * Uses IntersectionObserver for efficient lazy loading
 */
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

  // IntersectionObserver to detect when image enters viewport
  useEffect(() => {
    if (!imgRef.current) return;

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting) {
          setIsInView(true);
          observer.disconnect(); // Once in view, stop observing
        }
      },
      { threshold: 0.1, rootMargin: '50px' }
    );

    observer.observe(imgRef.current);
    return () => observer.disconnect();
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
