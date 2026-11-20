export function computeFitZoom(
    canvasWidth: number,
    canvasHeight: number,
    stageWidth: number,
    stageHeight: number,
    padding = 36,
): number | undefined {
    if (canvasWidth <= 0 || canvasHeight <= 0 || stageWidth <= 0 || stageHeight <= 0) {
        return undefined;
    }
    const availW = Math.max(160, stageWidth - padding);
    const availH = Math.max(160, stageHeight - padding);
    const scale = Math.min(availW / canvasWidth, availH / canvasHeight, 1);
    return Math.max(0.2, Math.round(scale * 1000) / 1000);
}

export function approxTextWidth(text: string, fontSize: number, bold = false): number {
    if (!text) return 0;
    const factor = bold ? 0.64 : 0.6;
    return Math.max(8, Math.ceil(text.length * fontSize * factor));
}

export interface StaticBadgeLayout {
    boxX: number;
    boxY: number;
    boxW: number;
    boxH: number;
    iconX: number;
    iconY: number;
    iconSize: number;
    textX: number;
    textY: number;
    hasIcon: boolean;
    hasText: boolean;
}

export function layoutStaticBadge(
    cx: number,
    cy: number,
    text: string,
    fontSize: number,
    hasIcon: boolean,
    iconSize: number,
): StaticBadgeLayout {
    const hasText = text.length > 0;
    const tw = hasText ? approxTextWidth(text, fontSize, true) : 0;
    const th = hasText ? fontSize + 2 : 0;
    const iconW = hasIcon ? iconSize : 0;
    const iconH = hasIcon ? iconSize : 0;
    const gap = hasIcon && hasText ? 5 : 0;
    const padX = 6;
    const padY = 4;
    const boxW = Math.max(20, iconW + gap + tw + padX * 2);
    const boxH = Math.max(18, Math.max(iconH, th) + padY * 2);
    const boxX = cx - boxW / 2;
    const boxY = cy - boxH / 2;
    return {
        boxX,
        boxY,
        boxW,
        boxH,
        iconX: boxX + padX,
        iconY: cy - iconH / 2,
        iconSize,
        textX: boxX + padX + iconW + gap + tw / 2,
        textY: cy,
        hasIcon,
        hasText,
    };
}

export interface AnnotationLayout {
    boxW: number;
    boxH: number;
    iconX: number;
    iconY: number;
    iconSize: number;
    textAnchor: 'start' | 'middle' | 'end';
    textX: number;
    textStartY: number;
    lineHeight: number;
    hasIcon: boolean;
    hasText: boolean;
}

export function layoutAnnotation(params: {
    lines: string[];
    style: 'plain' | 'card';
    align: 'left' | 'center' | 'right';
    fontSize: number;
    hasIcon: boolean;
    iconPosition: 'left' | 'top';
    iconSize: number;
}): AnnotationLayout {
    const { lines, style, align, fontSize, hasIcon, iconPosition, iconSize } = params;
    const hasText = lines.length > 0 && lines.some((l) => l.length > 0);
    const lineHeight = Math.max(fontSize + 4, Math.round(fontSize * 1.4));
    const maxLineW = hasText
        ? Math.max(...lines.map((l) => (l.length > 0 ? approxTextWidth(l, fontSize, false) : 0)))
        : 0;
    const textBlockW = maxLineW;
    const textBlockH = hasText ? lines.length * lineHeight : 0;
    const padX = style === 'card' ? 10 : 2;
    const padY = style === 'card' ? 8 : 2;
    const gap = hasIcon && hasText ? 8 : 0;

    let innerW: number;
    let innerH: number;
    if (iconPosition === 'top') {
        innerW = Math.max(hasIcon ? iconSize : 0, textBlockW);
        innerH = (hasIcon ? iconSize : 0) + gap + textBlockH;
    } else {
        innerW = (hasIcon ? iconSize : 0) + gap + textBlockW;
        innerH = Math.max(hasIcon ? iconSize : 0, textBlockH);
    }

    const boxW = Math.max(24, innerW + padX * 2);
    const boxH = Math.max(20, innerH + padY * 2);

    let iconX = padX;
    let iconY = padY;
    if (iconPosition === 'top') {
        if (align === 'center') iconX = padX + (innerW - iconSize) / 2;
        else if (align === 'right') iconX = padX + innerW - iconSize;
        else iconX = padX;
        iconY = padY;
    } else {
        iconX = padX;
        iconY = padY + (innerH - iconSize) / 2;
    }

    const textOriginX = iconPosition === 'top' ? padX : padX + (hasIcon ? iconSize + gap : 0);
    const textAreaW = iconPosition === 'top' ? innerW : textBlockW;
    const textStartY =
        iconPosition === 'top'
            ? padY + (hasIcon ? iconSize + gap : 0) + lineHeight / 2
            : padY + (innerH - textBlockH) / 2 + lineHeight / 2;

    const textAnchor: 'start' | 'middle' | 'end' =
        align === 'center' ? 'middle' : align === 'right' ? 'end' : 'start';
    const textX =
        align === 'center'
            ? textOriginX + textAreaW / 2
            : align === 'right'
              ? textOriginX + textAreaW
              : textOriginX;

    return {
        boxW,
        boxH,
        iconX,
        iconY,
        iconSize,
        textAnchor,
        textX,
        textStartY,
        lineHeight,
        hasIcon,
        hasText,
    };
}
