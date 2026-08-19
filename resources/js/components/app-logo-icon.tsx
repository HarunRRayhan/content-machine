import type { SVGAttributes } from 'react';

/**
 * The content-machine mark: two right-facing chevrons — one solid, one
 * trailing at reduced opacity — reading as a single forward/flow motion.
 * Literal enough to survive at favicon size, abstract enough not to be a
 * bare arrow icon. Renders in `currentColor`; callers supply the plate
 * background (see AppLogo).
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">
            <polygon
                points="8,6 22,20 8,34 14,34 28,20 14,6"
                fill="currentColor"
            />
            <polygon
                points="20,11 32,20 20,29 25,29 37,20 25,11"
                fill="currentColor"
                opacity="0.42"
            />
        </svg>
    );
}
