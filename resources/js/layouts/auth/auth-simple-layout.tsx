import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-background px-5 py-12">
            <div className="w-full max-w-md rounded-xl border bg-card p-6 sm:p-9">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col gap-5">
                        <Link
                            href={home()}
                            className="flex w-fit items-center gap-2 font-semibold tracking-tight"
                        >
                            <img
                                src="/cv-mark.svg"
                                alt=""
                                width={32}
                                height={32}
                                className="size-8"
                            />
                            <span>CV Generator</span>
                        </Link>

                        <div className="space-y-2">
                            <h1 className="text-3xl font-semibold tracking-tight">
                                {title}
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
