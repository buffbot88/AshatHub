import { useRef, useState, useEffect, useCallback } from 'react';
import type { ReactNode } from 'react';

interface VirtualizedListProps<T> {
  items: T[];
  itemHeight: number;
  overscan?: number;
  renderItem: (item: T, index: number) => ReactNode;
  className?: string;
  onEndReached?: () => void;
  endReachedThreshold?: number;
}

/**
 * Simple virtualized list that only renders visible items plus overscan.
 * Uses a scroll container with absolute positioning for items.
 */
export function VirtualizedList<T>({
  items,
  itemHeight,
  overscan = 5,
  renderItem,
  className,
  onEndReached,
  endReachedThreshold = 100,
}: VirtualizedListProps<T>) {
  const containerRef = useRef<HTMLDivElement>(null);
  const [scrollTop, setScrollTop] = useState(0);
  const [containerHeight, setContainerHeight] = useState(400);

  const totalHeight = items.length * itemHeight;
  const startIndex = Math.max(0, Math.floor(scrollTop / itemHeight) - overscan);
  const endIndex = Math.min(
    items.length,
    Math.ceil((scrollTop + containerHeight) / itemHeight) + overscan,
  );
  const visibleItems = items.slice(startIndex, endIndex);

  const handleScroll = useCallback(() => {
    if (containerRef.current) {
      setScrollTop(containerRef.current.scrollTop);
    }
  }, []);

  // Measure container height on mount and resize
  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;

    const observer = new ResizeObserver((entries) => {
      for (const entry of entries) {
        setContainerHeight(entry.contentRect.height);
      }
    });

    observer.observe(container);
    setContainerHeight(container.clientHeight);

    return () => observer.disconnect();
  }, []);

  // Check if end is reached
  useEffect(() => {
    if (!onEndReached) return;
    const remainingHeight = totalHeight - (scrollTop + containerHeight);
    if (remainingHeight < endReachedThreshold) {
      onEndReached();
    }
  }, [scrollTop, containerHeight, totalHeight, onEndReached, endReachedThreshold]);

  return (
    <div
      ref={containerRef}
      className={className}
      onScroll={handleScroll}
      style={{ overflow: 'auto', position: 'relative' }}
    >
      <div style={{ height: totalHeight, position: 'relative' }}>
        {visibleItems.map((item, i) => {
          const actualIndex = startIndex + i;
          return (
            <div
              key={actualIndex}
              style={{
                position: 'absolute',
                top: actualIndex * itemHeight,
                left: 0,
                right: 0,
                height: itemHeight,
              }}
            >
              {renderItem(item, actualIndex)}
            </div>
          );
        })}
      </div>
    </div>
  );
}
