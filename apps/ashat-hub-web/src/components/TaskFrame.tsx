import { useEffect, useState } from 'react';

export type TaskStatus = 'queued' | 'working' | 'waiting' | 'complete' | 'failed' | 'cancelled';

export interface TaskEvent {
  id: string;
  message: string;
  kind: 'progress' | 'success' | 'warning' | 'error';
  timestamp: string;
}

export interface TaskFrameData {
  id: string;
  title: string;
  status: TaskStatus;
  phase: string;
  startedAt: string;
  events: TaskEvent[];
}

interface TaskFrameProps {
  task: TaskFrameData | null;
}

export function TaskFrame({ task }: TaskFrameProps) {
  const [expanded, setExpanded] = useState(task?.status === 'working');

  useEffect(() => {
    if (!task) return;
    if (task.status === 'complete' || task.status === 'failed' || task.status === 'cancelled') {
      setExpanded(false);
    } else if (task.status === 'working') {
      setExpanded(true);
    }
  }, [task?.id, task?.status]);

  if (!task) {
    return (
      <section className="task-frame task-frame-empty" aria-label="Task activity">
        <span className="eyebrow">Task activity</span>
        <span className="muted">No active tasks</span>
      </section>
    );
  }

  const isActive = task.status === 'queued' || task.status === 'working' || task.status === 'waiting';
  const latest = task.events.at(-1);

  return (
    <section className={`task-frame ${isActive ? 'is-active' : ''}`} aria-label={`${task.title} task`}>
      <button className="task-frame-header" type="button" onClick={() => setExpanded((value) => !value)}>
        <span className="task-frame-chevron" aria-hidden="true">{expanded ? '▾' : '▸'}</span>
        <span className={`task-status-dot status-${task.status}`} aria-hidden="true" />
        <span className="task-frame-title">{task.title}</span>
        <span className="task-frame-phase">{isActive ? task.phase : task.status}</span>
      </button>
      {latest && !expanded && <p className="task-frame-latest">{latest.message}</p>}
      {expanded && (
        <div className="task-frame-body">
          {task.events.map((event) => (
            <div className={`task-event event-${event.kind}`} key={event.id}>
              <span aria-hidden="true">{event.kind === 'success' ? '✓' : event.kind === 'error' ? '!' : '◇'}</span>
              <span>{event.message}</span>
              <time dateTime={event.timestamp}>{new Date(event.timestamp).toLocaleTimeString()}</time>
            </div>
          ))}
          {isActive && <div className="task-heartbeat"><span className="heartbeat-dot" /> Ashat is still working...</div>}
        </div>
      )}
    </section>
  );
}
