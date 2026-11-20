export default function AppLogo() {
    return (
        <>
            <img
                src="/cv-mark.svg"
                alt=""
                width={32}
                height={32}
                className="size-8 shrink-0"
            />
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    CV Generator
                </span>
            </div>
        </>
    );
}
