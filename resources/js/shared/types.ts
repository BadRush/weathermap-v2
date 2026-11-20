export interface Point {
    x: number;
    y: number;
}

export interface CanvasConfig {
    width: number;
    height: number;
    bgColor: string;
    backgroundAssetId: number | null;
    backgroundAssetUrl?: string | null;
    title: string;
    titleX: number;
    titleY: number;
    titleFontSize: number;
    titleColor: string;
    showTimestamp: boolean;
    timestampX: number;
    timestampY: number;
    timestampFontSize: number;
    timestampColor: string;
}

export interface TargetTerm {
    source: 'port' | 'rrd' | 'static';
    portId: number | null;
    rrdPath: string | null;
    dsIn: string;
    dsOut: string;
    cf: 'AVERAGE' | 'MAX' | 'MIN' | 'LAST';
    staticIn: number;
    staticOut: number;
    factor: number;
    negate: boolean;
    reverse: boolean;
}

export interface TargetExpr {
    terms: TargetTerm[];
}

export interface ScaleStop {
    min: number;
    max: number;
    rgb1: [number, number, number];
    rgb2: [number, number, number] | null;
    label: string | null;
}

export interface ScaleDef {
    name: string;
    stops: ScaleStop[];
}

export interface LegendDef {
    visible: boolean;
    scaleName: string;
    x: number;
    y: number;
    title: string;
    style: 'classic' | 'horizontal' | 'vertical';
    bgColor: string;
    outlineColor: string;
    textColor: string;
    fontSize: number;
}

export interface NodeDef {
    id: string;
    label: string;
    x: number;
    y: number;
    iconType: 'shape:box' | 'shape:round' | 'device-os' | 'asset';
    iconAssetId: number | null;
    deviceOsIcon: string | null;
    iconWidth: number;
    iconHeight: number;
    deviceId: number | null;
    labelOffset: string;
    labelFontSize: number;
    labelColor: string;
    bgColor: string;
    outlineColor: string;
    zOrder: number;
    infoUrl: string | null;
    scaleName: string | null;
    target: TargetExpr | null;
    maxValue: number;
}

export interface LinkEndpoint {
    nodeId: string;
    offset: string;
}

export interface CapacityDef {
    source: 'ifSpeed' | 'manual';
    inBps: number;
    outBps: number;
}

export interface AnnotationDef {
    id: string;
    text: string;
    x: number;
    y: number;
    style: 'plain' | 'card';
    align: 'left' | 'center' | 'right';
    fontSize: number;
    textColor: string;
    bgColor: string;
    borderColor: string;
    iconAssetId: number | null;
    iconPosition: 'left' | 'top';
    iconSize: number;
    infoUrl: string | null;
    zOrder: number;
}

export interface LinkDef {
    id: string;
    a: LinkEndpoint;
    b: LinkEndpoint;
    via: Point[];
    viaStyle: 'curved' | 'angled';
    width: number;
    arrowStyle: 'classic' | 'compact';
    splitPos: number;
    bwLabel: 'percent' | 'bits' | 'none';
    inLabelPos: number;
    outLabelPos: number;
    bwFontSize: number;
    outlineColor: string;
    scaleName: string;
    capacity: CapacityDef;
    target: TargetExpr;
    infoUrl: string | null;
    inComment: string;
    outComment: string;
    zOrder: number;
    staticLabel: string;
    staticLabelPos: number;
    staticLabelOffsetX: number;
    staticLabelOffsetY: number;
    staticLabelFontSize: number;
    staticLabelBgColor: string;
    staticLabelTextColor: string;
    vendorIconAssetId: number | null;
    vendorIconSize: number;
}

export interface MapSettings {
    refreshSeconds: number;
    rrdPeriodSeconds: number;
    stalenessSeconds: number;
    gridSize: number;
    snapToGrid: boolean;
}

export interface MapDocument {
    schemaVersion: number;
    canvas: CanvasConfig;
    nodes: NodeDef[];
    links: LinkDef[];
    annotations: AnnotationDef[];
    scales: ScaleDef[];
    legend: LegendDef;
    settings: MapSettings;
}

export interface SceneStaticBadge {
    text: string;
    pos: Point;
    fontSize: number;
    bgColor: string;
    textColor: string;
    borderColor: string;
    iconAssetId: number | null;
    iconAssetUrl: string | null;
    iconSize: number;
}

export interface SceneAnnotation {
    id: string;
    text: string;
    lines: string[];
    x: number;
    y: number;
    style: 'plain' | 'card';
    align: 'left' | 'center' | 'right';
    fontSize: number;
    textColor: string;
    bgColor: string;
    borderColor: string;
    iconAssetId: number | null;
    iconAssetUrl: string | null;
    iconPosition: 'left' | 'top';
    iconSize: number;
    infoUrl: string | null;
    zOrder: number;
}

export interface SceneArrow {
    polygon: Point[];
    color: string;
    bps: number | null;
    bpsFormatted: string;
    percent: number | null;
    capacityBps: number;
    capacityFormatted: string;
    label: string;
    labelPos: Point;
    labelTextColor: string;
}

export interface SceneLink {
    id: string;
    nodeA: string;
    nodeB: string;
    zOrder: number;
    width: number;
    outlineColor: string;
    centerline: Point[];
    outArrow: SceneArrow;
    inArrow: SceneArrow;
    bwFontSize: number;
    inComment: string;
    outComment: string;
    inCommentPos?: Point | null;
    outCommentPos?: Point | null;
    staticBadge?: SceneStaticBadge | null;
    isDown: boolean;
    isStale: boolean;
    dataTimestamp: number | null;
    primaryPortId: number | null;
    primaryDeviceId: number | null;
    portLabel: string | null;
    infoUrl: string | null;
    graphUrl: string | null;
    error: string | null;
}

export interface SceneNode {
    id: string;
    label: string;
    x: number;
    y: number;
    iconType: 'shape:box' | 'shape:round' | 'device-os' | 'asset';
    iconAssetId: number | null;
    iconAssetUrl: string | null;
    deviceOsIcon: string | null;
    iconWidth: number;
    iconHeight: number;
    deviceId: number | null;
    labelPos: Point;
    labelFontSize: number;
    labelColor: string;
    bgColor: string;
    outlineColor: string;
    zOrder: number;
    infoUrl: string | null;
    value: number | null;
    percent: number | null;
    isDown: boolean;
    isStale: boolean;
}

export interface SceneLegendStop {
    min: number;
    max: number;
    color1: string;
    color2: string | null;
    label: string;
}

export interface SceneLegend {
    x: number;
    y: number;
    title: string;
    style: 'classic' | 'horizontal' | 'vertical';
    bgColor: string;
    outlineColor: string;
    textColor: string;
    fontSize: number;
    stops: SceneLegendStop[];
}

export interface Scene {
    generatedAt: number;
    timestampText: string;
    canvas: CanvasConfig;
    nodes: SceneNode[];
    links: SceneLink[];
    annotations?: SceneAnnotation[];
    legend: SceneLegend | null;
    refreshSeconds: number;
}

export interface AccessRule {
    subject_type: 'role' | 'user';
    subject_id: string;
}

export interface MapDetail {
    id: number;
    name: string;
    slug: string;
    revision: number;
    schema_version: number;
    png_enabled: boolean;
    last_rendered_at: string | null;
    last_render_error: string | null;
    document: MapDocument;
    accessRules: AccessRule[];
}

export interface DeviceLookupItem {
    device_id: number;
    hostname: string;
    display: string;
    os: string;
    status: boolean;
}

export interface PortLookupItem {
    port_id: number;
    device_id: number;
    hostname: string;
    ifName: string;
    ifAlias: string;
    ifSpeed: number;
    ifOperStatus: string;
}

export type AssetKind = 'background' | 'icon' | 'vendor';

export interface AssetItem {
    id: number;
    kind: AssetKind;
    original_name: string;
    mime: string;
    size: number;
    url: string;
}

export interface ImportReport {
    nodesImported: number;
    linksImported: number;
    scalesImported: number;
    convertedTargets: string[];
    warnings: string[];
    skippedDirectives: string[];
}
