import { Link } from '@inertiajs/react';
import { edit as editPostsyncer } from '@/routes/settings/postsyncer';

export type PostsyncerStepId = 'connecting' | 'bangla' | 'english';

export type PostsyncerStepState = {
    unlocked: boolean;
    done: boolean;
};

const STEPS: { id: PostsyncerStepId; title: string; number: number }[] = [
    { id: 'connecting', title: 'Connecting', number: 1 },
    { id: 'bangla', title: 'Bangla', number: 2 },
    { id: 'english', title: 'English', number: 3 },
];

type PostsyncerStepsProps = {
    active: PostsyncerStepId;
    steps: Record<PostsyncerStepId, PostsyncerStepState>;
};

export function PostsyncerSteps({ active, steps }: PostsyncerStepsProps) {
    return (
        <div className="stepper" role="tablist" aria-label="PostSyncer setup">
            {STEPS.map((step, index) => {
                const state = steps[step.id];
                const current = step.id === active;
                const className = current
                    ? 'step cur'
                    : state.done
                      ? 'step done'
                      : 'step';

                return (
                    <div key={step.id} className="contents">
                        {index > 0 && <div className="conn" aria-hidden />}
                        {state.unlocked ? (
                            <Link
                                href={editPostsyncer.url(step.id)}
                                role="tab"
                                aria-selected={current}
                                className={className}
                            >
                                <span className="dot">{step.number}</span>
                                <span className="slabel">{step.title}</span>
                            </Link>
                        ) : (
                            <span
                                role="tab"
                                aria-selected={false}
                                aria-disabled="true"
                                className={className}
                            >
                                <span className="dot">{step.number}</span>
                                <span className="slabel">{step.title}</span>
                            </span>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
