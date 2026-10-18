# handshake: one static Go binary on an empty image. Built on the runner's own
# platform and cross-compiled for the target (no emulation), tested before it
# is built: an image that cannot tell the hybrid from X25519 is never pushed.
FROM --platform=$BUILDPLATFORM golang:1.25-alpine@sha256:1ae0735f00daffa3aaf1363a5184c0d2dc55c78e3db4ec70241cdac97bf84b59 AS build
ARG TARGETOS TARGETARCH
WORKDIR /src
COPY go.mod *.go ./
RUN go vet ./... && go test ./...
RUN CGO_ENABLED=0 GOOS=$TARGETOS GOARCH=$TARGETARCH go build -trimpath -ldflags="-s -w" -o /handshake .

FROM scratch
COPY --from=build /handshake /handshake
USER 65532:65532
EXPOSE 8443
ENTRYPOINT ["/handshake"]
